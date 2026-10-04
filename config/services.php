<?php

declare(strict_types=1);

use App\Domain\Auth\PasswordPolicy;
use App\Domain\Workspace\Permissions;
use App\Http\WorkspaceNav;
use App\Domain\Auth\RegistrationService;
use App\Domain\Auth\RememberMe;
use App\Kernel\Config;
use App\Kernel\Console\Command\SeedCommand;
use App\Kernel\Container;
use App\Kernel\Database\Connection;
use App\Kernel\Database\Migrator;
use App\Kernel\HealthCheck;
use App\Kernel\Http\RequestContext;
use App\Kernel\Http\Router;
use App\Kernel\HttpClient\GuzzleHttpClient;
use App\Kernel\HttpClient\HttpClientInterface;
use App\Kernel\Log\LoggerFactory;
use App\Kernel\Mail\Mailer;
use App\Kernel\Mail\SymfonyMailer;
use App\Kernel\Queue\Schedule;
use App\Kernel\Security\Crypto;
use App\Kernel\Security\Csrf;
use App\Kernel\Security\PasswordHasher;
use App\Kernel\Session\RedisSessionStore;
use App\Kernel\Session\SessionStore;
use App\Kernel\View\Translator;
use App\Kernel\View\View;
use App\Support\Clock;
use App\Support\SystemClock;
use Psr\Log\LoggerInterface;

/**
 * Explicit service wiring: everything the autowirer cannot infer from type hints
 * (scalars, interfaces, shared resources). `$base` is the project root.
 */
return static function (Container $c, string $base): void {
    $c->factory(Clock::class, static fn (): Clock => new SystemClock());

    $c->factory(LoggerInterface::class, static function (Container $c) use ($base): LoggerInterface {
        $config = $c->get(Config::class);

        return LoggerFactory::create(
            $base . '/storage/logs',
            $config->string('app.log_level', 'info'),
            !$config->env()->bool('LOG_DISABLE_STDERR'),
        );
    });

    $c->factory(Connection::class, static function (Container $c): Connection {
        $config = $c->get(Config::class);

        return new Connection(
            $config->string('database.host'),
            $config->int('database.port', 3306),
            $config->string('database.database'),
            $config->string('database.username'),
            $config->string('database.password'),
        );
    });

    $c->factory(Migrator::class, static fn (Container $c): Migrator => new Migrator($c->get(Connection::class), $base . '/database/migrations'));

    $c->factory(\Redis::class, static function (Container $c): \Redis {
        $config = $c->get(Config::class);
        $redis = new \Redis();
        $redis->connect($config->string('database.redis.host'), $config->int('database.redis.port', 6379), 2.0);
        $redis->select($config->int('database.redis.db', 0));

        return $redis;
    });

    $c->factory(SessionStore::class, static fn (Container $c): SessionStore => new RedisSessionStore($c->get(\Redis::class)));

    $c->factory(Crypto::class, static function (Container $c): Crypto {
        $config = $c->get(Config::class);
        $keys = [$config->string('security.key_id') => $config->string('security.key')];
        foreach ($config->array('security.old_keys') as $id => $key) {
            $keys[(string) $id] = is_string($key) ? $key : '';
        }

        return new Crypto($config->string('security.key_id'), $keys);
    });

    $c->factory(PasswordHasher::class, static function (Container $c): PasswordHasher {
        $config = $c->get(Config::class);

        return new PasswordHasher($config->int('security.argon.memory_kib', 65536), $config->int('security.argon.time_cost', 4));
    });

    $c->factory(Mailer::class, static function (Container $c): Mailer {
        $config = $c->get(Config::class);

        return new SymfonyMailer($config->string('mail.dsn'), $config->string('mail.from'), $config->string('mail.from_name'));
    });

    $c->factory(PasswordPolicy::class, static function (Container $c) use ($base): PasswordPolicy {
        return new PasswordPolicy(
            $c->get(HttpClientInterface::class),
            $c->get(LoggerInterface::class),
            $c->get(Config::class)->bool('auth.hibp_enabled'),
            $base . '/resources/data/common-passwords.txt',
        );
    });

    $c->factory(RememberMe::class, static fn (Container $c): RememberMe => new RememberMe(
        $c->get(Connection::class),
        $c->get(Clock::class),
        $c->get(Config::class)->int('auth.remember_days', 30),
    ));

    $c->factory(RegistrationService::class, static fn (Container $c): RegistrationService => new RegistrationService(
        $c->get(\App\Domain\User\UserRepository::class),
        $c->get(PasswordHasher::class),
        $c->get(\App\Domain\Auth\AuthTokens::class),
        $c->get(\App\Domain\Auth\AuthMailer::class),
        $c->get(\App\Kernel\Security\RateLimiter::class),
        $c->get(\App\Domain\Audit\AuditLog::class),
        $c->get(\App\Domain\Workspace\WorkspaceService::class),
        $c->get(Config::class)->string('auth.consent_version'),
    ));

    $c->factory(\App\Domain\Auth\Social\SocialAuthService::class, static fn (Container $c): \App\Domain\Auth\Social\SocialAuthService => new \App\Domain\Auth\Social\SocialAuthService(
        $c->get(\App\Domain\Auth\Social\IdentityRepository::class),
        $c->get(\App\Domain\User\UserRepository::class),
        $c->get(\App\Domain\Audit\AuditLog::class),
        $c->get(\App\Kernel\Security\RateLimiter::class),
        $c->get(Clock::class),
        $c->get(\App\Domain\Workspace\WorkspaceService::class),
        $c->get(Config::class)->string('auth.consent_version'),
    ));

    $c->factory(HttpClientInterface::class, static fn (Container $c): HttpClientInterface => $c->get(GuzzleHttpClient::class));

    $c->factory(Translator::class, static fn (): Translator => new Translator($base . '/resources/lang', 'ru'));

    $c->factory(Permissions::class, static function (Container $c): Permissions {
        $matrix = [];
        foreach ($c->get(Config::class)->array('permissions') as $permission => $roles) {
            $matrix[(string) $permission] = is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];
        }

        return new Permissions($matrix);
    });

    $c->factory(View::class, static function (Container $c) use ($base): View {
        $view = new View(
            $c->get(Config::class),
            $c->get(RequestContext::class),
            $c->get(Csrf::class),
            $c->get(Router::class),
            $c->get(Translator::class),
            $base . '/templates',
            $base . '/public',
            $base . '/storage/cache/twig',
        );
        // Workspace-aware helpers live above the Kernel, so they are attached here.
        $nav = $c->get(WorkspaceNav::class);
        $view->registerFunction('can', $nav->can(...));
        $view->registerFunction('current_workspace', $nav->current(...));
        $view->registerFunction('my_workspaces', $nav->mine(...));
        $view->registerFunction('workspace_nav', $nav->items(...));

        return $view;
    });

    $c->factory(HealthCheck::class, static fn (Container $c): HealthCheck => new HealthCheck($c->get(Config::class)->env()->all()));

    $c->factory(Schedule::class, static function (Container $c) use ($base): Schedule {
        $schedule = new Schedule($c, $c->get(LoggerInterface::class));
        $define = require $base . '/config/schedule.php';
        $define($schedule);

        return $schedule;
    });

    $c->factory(SeedCommand::class, static fn (Container $c): SeedCommand => new SeedCommand(
        $c->get(Connection::class),
        $c->get(Config::class),
        $base . '/database/seeds',
    ));
};
