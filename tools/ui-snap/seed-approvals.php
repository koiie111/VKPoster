<?php

declare(strict_types=1);

// Isolated app_test fixture; synthetic guest capability only, never production data or credentials.
require dirname(__DIR__,2).'/vendor/autoload.php';
$fixture=new class ('fixture') extends \App\Tests\Support\BillingTestCase {
    public function fixture(): void
    {
        $this->setUp();
        if ($this->db->select('SELECT DATABASE() AS name')[0]['name']!=='app_test') {
            throw new \LogicException('Disposable database required');
        }
        [$owner,$ws]=$this->ownerWithWorkspace('approvals-ui@example.com','Approval UI');
        $this->payWithFake($ws,$owner,'agency');
        $staff=$this->createUser('approvals-staff-ui@example.com',true,null,'Сотрудник UI');
        $this->app->container()->get(\App\Domain\User\UserRepository::class)->setSuperadmin($staff->id,true);
        $ctx=$this->contextFor($ws,$owner);
        $author=$this->memberOf($ws,'approvals-author-ui@example.com',\App\Domain\Workspace\Role::Author,'Автор UI');
        $writer=$this->contextFor($ws,$author);
        $channel=$this->fakeChannel($ws,$owner,'approval-ui','Тестовый канал');
        $c=$this->app->container();
        $workflow=$c->get(\App\Domain\Approval\ApprovalWorkflow::class);
        $workflow->configure($ctx,true,[],'Approval Studio','#4338ca');
        $p=$this->service()->saveDraft($writer,null,$this->draft("Пост на согласование\nСинтетический текст для проверки preview и версии.",[$channel]));
        $workflow->submit($writer,$p);
        $p=$this->posts()->find($ctx,$p->publicId) ?? throw new \LogicException();
        $workflow->decide($ctx,$p,$workflow->revision($ctx,$p),true);
        $p=$this->posts()->find($ctx,$p->publicId) ?? throw new \LogicException();
        $p=$this->service()->saveDraft($writer,$p,$this->draft("Пост на согласование\nОбновлённый текст: согласуйте актуальную версию.",[$channel]));
        $comments=$c->get(\App\Domain\Approval\PostComments::class);
        $comments->add($ctx,$p,'Внутреннее обсуждение: проверьте текст @Автор_UI');
        $first=$comments->thread($ctx,$p)[0];
        $comments->add($writer,$p,'Текст обновлён, готов к проверке.',$first['public_id']);
        $guest=$c->get(\App\Domain\Approval\GuestLinks::class)->create($ctx,[$p->publicId],72);
        $pin=$c->get(\App\Domain\Approval\GuestLinks::class)->create($ctx,[$p->publicId],72,'123456');
        file_put_contents('storage/testing/approvals-ui.json',json_encode(['workspace'=>$ws->publicId,'post'=>$p->publicId,'guest'=>$guest,'pin'=>$pin],JSON_THROW_ON_ERROR));
        echo 'Synthetic approval UI fixture ready.',PHP_EOL;
    }
};
$fixture->fixture();
