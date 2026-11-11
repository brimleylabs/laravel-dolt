<?php

namespace BrimleyLabs\Dolt\Tests\Fixtures;

use BrimleyLabs\Dolt\Facades\Dolt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

class PublishPost implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public string $title,
        public ?string $message = null,
        public bool $fail = false,
    ) {}

    public function handle(): void
    {
        Post::create(['title' => $this->title, 'status' => 'published']);

        if ($this->message !== null) {
            Dolt::message($this->message);
        }

        if ($this->fail) {
            throw new RuntimeException('The job broke after writing.');
        }
    }
}
