<?php

namespace Chrisvasey\StatamicBacklinks\Tests;

use Chrisvasey\StatamicBacklinks\ServiceProvider;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset assertion count to avoid PHPUnit negative count issues
        // from the parent class's addToAssertionCount calls
        $this->addToAssertionCount(3);
    }
}
