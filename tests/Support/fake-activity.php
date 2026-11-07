<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus {
    // The marker class the mirror looks for.
    class ActivityLogPlusServiceProvider {}
}

namespace {
    final class FakeActivity
    {
        /** @var list<array<string, mixed>> */
        public static array $entries = [];

        public static bool $throw = false;

        /** @var array<string, mixed> */
        private array $row = [];

        public function __construct(string $log)
        {
            $this->row['log'] = $log;
        }

        public function performedOn(object $subject): self
        {
            $this->row['subject'] = get_class($subject);

            return $this;
        }

        public function event(string $event): self
        {
            $this->row['event'] = $event;

            return $this;
        }

        /** @param array<string, mixed> $properties */
        public function withProperties(array $properties): self
        {
            $this->row['properties'] = $properties;

            return $this;
        }

        public function causedBy(object $causer): self
        {
            $this->row['causer'] = (string) $causer->getAuthIdentifier();

            return $this;
        }

        public function log(string $description): void
        {
            if (self::$throw) {
                throw new RuntimeException('activity_log table is missing');
            }

            self::$entries[] = $this->row + ['description' => $description];
        }
    }

    if (! function_exists('activity')) {
        function activity(?string $log = null): FakeActivity
        {
            return new FakeActivity((string) $log);
        }
    }
}
