<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\PasswordPolicy;
use App\Domain\Auth\RegistrationService;
use App\Domain\User\UserRepository;
use App\Http\Auth\SocialFlow;
use App\Http\FormFlash;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Validation\Validator;
use App\Kernel\View\View;

/**
 * Sign-up form and the "check your mail" page. The answer is the same for new and existing emails.
 */
final class RegisterController
{
    public function __construct(
        private readonly View $view,
        private readonly Validator $validator,
        private readonly PasswordPolicy $policy,
        private readonly RegistrationService $registration,
        private readonly FormFlash $flash,
        private readonly SocialFlow $social,
    ) {
    }

    public function show(): Response
    {
        // Telegram's widget is loaded on the sign-in page only; here its button leads there.
        return $this->view->response('auth/register.twig', ['social' => ['buttons' => $this->social->buttons(), 'telegram' => null]]);
    }

    public function store(Request $request): Response
    {
        $input = [
            'name' => $this->text($request->input('name')),
            'email' => mb_strtolower($this->text($request->input('email'))),
            'password' => is_string($request->input('password')) ? $request->input('password') : '',
            'consent' => $request->input('consent'),
        ];
        $validation = $this->validator->make($input, [
            'name' => 'required|string|min:2|max:100',
            'email' => 'required|string|email|max:254',
            'password' => 'required|string',
        ], ['name' => 'Как вас зовут', 'email' => 'Почта', 'password' => 'Пароль']);
        $errors = $validation->errors();
        if (!isset($errors['password'])) {
            $problem = $this->policy->check($input['password'], $input['email']);
            if ($problem !== null) {
                $errors['password'] = [$problem];
            }
        }
        if ($input['consent'] !== '1') {
            $errors['consent'] = ['Чтобы создать аккаунт, согласитесь на обработку персональных данных.'];
        }
        if ($errors !== []) {
            $this->flash->invalid($input + ['consent' => $input['consent'] === '1' ? '1' : ''], $errors);

            return Response::redirect('/register');
        }
        $this->registration->register($input['email'], $input['name'], $input['password']);
        $this->flash->session()->flash('register.email', $input['email']);

        return Response::redirect('/register/done');
    }

    public function done(): Response
    {
        $email = $this->flash->session()->getFlash('register.email');
        if (!is_string($email)) {
            return Response::redirect('/register');
        }

        return $this->view->response('auth/register_done.twig', ['email' => $email]);
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '') : '';
    }
}
