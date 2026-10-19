<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Api\{ApiIdentity, ApiResources};
use App\Kernel\Http\{Request, Response};

/** Thin API request adapters; domain rules remain in PostService, MediaService and PostAnalytics. */
final class ResourcesController
{
    public function __construct(private readonly ApiResources $resources)
    {
    }

    public function channels(Request $request): Response
    {
        return Response::json($this->resources->channels($this->who($request), $request));
    }

    public function posts(Request $request): Response
    {
        return Response::json($this->resources->posts($this->who($request), $request));
    }

    public function create(Request $request): Response
    {
        return Response::json(['data' => $this->resources->save($this->who($request), $request)], 201);
    }

    public function show(Request $request, string $id): Response
    {
        return Response::json(['data' => $this->resources->show($this->who($request), $id)]);
    }

    public function update(Request $request, string $id): Response
    {
        return Response::json(['data' => $this->resources->save($this->who($request), $request, $id)]);
    }

    public function delete(Request $request, string $id): Response
    {
        $this->resources->delete($this->who($request), $id);
        return Response::json(['data' => ['deleted' => true]]);
    }

    public function schedule(Request $request, string $id): Response
    {
        return Response::json(['data' => $this->resources->schedule($this->who($request), $id, $request)]);
    }

    public function unschedule(Request $request, string $id): Response
    {
        return Response::json(['data' => $this->resources->unschedule($this->who($request), $id)]);
    }

    public function publication(Request $request, string $id): Response
    {
        return Response::json(['data' => $this->resources->publication($this->who($request), $id)]);
    }

    public function media(Request $request): Response
    {
        return Response::json(['data' => $this->resources->upload($this->who($request), $request)], 201);
    }

    public function stats(Request $request): Response
    {
        return Response::json(['data' => $this->resources->stats($this->who($request), $request)]);
    }

    private function who(Request $request): ApiIdentity
    {
        $identity = $request->attribute('api');
        return $identity instanceof ApiIdentity ? $identity : throw new \LogicException('Missing API identity.');
    }
}
