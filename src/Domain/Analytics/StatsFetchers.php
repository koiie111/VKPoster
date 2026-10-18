<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Integrations\Social\Contracts\{Platform,StatsFetcher};
use App\Integrations\Social\Fake\FakeStatsFetcher;
use App\Integrations\Social\Vk\VkStatsFetcher;
use App\Integrations\Social\Telegram\TelegramStatsFetcher;
use App\Integrations\Social\Max\MaxStatsFetcher;
use App\Integrations\Social\Instagram\InstagramStatsFetcher;

/** Typed registry reuses the existing API clients and their platform rate/error gates. */
final class StatsFetchers
{
    public function __construct(
        private readonly FakeStatsFetcher $fake,
        private readonly VkStatsFetcher $vk,
        private readonly TelegramStatsFetcher $telegram,
        private readonly MaxStatsFetcher $max,
        private readonly InstagramStatsFetcher $instagram
    ) {
    }
    public function for(Platform $platform): StatsFetcher
    {
        return match($platform) {
            Platform::Fake => $this->fake,
            Platform::Vk => $this->vk,
            Platform::Telegram => $this->telegram,
            Platform::Max => $this->max,
            Platform::Instagram => $this->instagram
        };
    }
}
