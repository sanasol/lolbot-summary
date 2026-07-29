<?php

require __DIR__ . '/../vendor/autoload.php';

class TestableClickhouseAgent extends App\Services\ClickhouseAgent
{
    public function normalize(string $query): string
    {
        return $this->normalizeDonatorNameLookup($query);
    }
}

$agent = new TestableClickhouseAgent();

$memberLookup = $agent->normalize("SELECT id FROM stripchat.donators WHERE name = 'Fearless_soul9' LIMIT 1");
if ($memberLookup !== "SELECT id FROM stripchat.donators WHERE lowerUTF8(name) = lowerUTF8('Fearless_soul9') LIMIT 1") {
    throw new RuntimeException('Member lookup was not normalized.');
}

$roomLookup = $agent->normalize("SELECT id FROM stripchat.rooms WHERE name = 'SomeModel' LIMIT 1");
if ($roomLookup !== "SELECT id FROM stripchat.rooms WHERE name = 'SomeModel' LIMIT 1") {
    throw new RuntimeException('Unrelated room lookup was changed.');
}

echo "ok\n";
