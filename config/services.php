<?php

declare(strict_types=1);

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

    $c->factory(HttpClientInterface::class, static fn (Container $c): HttpClientInterface => $c->get(GuzzleHttpClient::class));

    $c->factory(Translator::class, static fn (): Translator => new Translator($base . '/resources/lang', 'ru'));

    $c->factory(View::class, static fn (Container $c): View => new View(
        $c->get(Config::class),
        $c->get(RequestContext::class),
        $c->get(Csrf::class),
        $c->get(Router::class),
        $c->get(Translator::class),
        $base . '/templates',
        $base . '/public',
        $base . '/storage/cache/twig',
    ));

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
