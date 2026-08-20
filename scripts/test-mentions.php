<?php

/**
 * Self-checks for reading a call to the assistant out of a post:
 * php -d zend.assertions=1 scripts/test-mentions.php
 */

use Wszdb\FlarumAiChat\Mentions;

require __DIR__ . '/../src/Mentions.php';

$bot = 4242;
$name = 'assistant-bot';
$calls = fn (string $content): bool => Mentions::callsBot($content, $bot, $name);

// the shape the mention autocomplete writes
assert($calls('@"The Assistant"#4242 what is this camera?'), 'the display-name form calls the bot');
assert($calls("line one\n@\"The Assistant\"#4242"), 'a mention on any line calls the bot');
assert(!$calls('@"Someone Else"#31 what is this camera?'), 'another member is not the bot');
assert($calls('@"Someone Else"#31 and @"The Assistant"#4242'), 'one call among several is a call');

// an id that merely starts with the bot's is not the bot
assert(!$calls('@"Another"#42420'), 'a longer id is a different member');
assert(!$calls('@"Another"#424'), 'a shorter id is a different member');

// the plain form the allow_username_format setting keeps alive
assert($calls('hey @assistant-bot, any idea?'), 'the plain form calls the bot');
assert($calls('@ASSISTANT-BOT'), 'the plain form ignores case');
assert(!$calls('mail me at me@assistant-bot.example'), 'an address is not a mention');
assert(!$calls('@assistant-bots is someone else'), 'a longer name is a different member');
assert(!$calls('ask the assistant-bot about it'), 'the name without an @ is not a call');

// nothing to match on
assert(!$calls('what is this camera?'), 'a post with no mention is not a call');
assert(!Mentions::callsBot('@"The Assistant"#4242', null, null), 'no bot means no call');
assert(!Mentions::callsBot(null, $bot, $name), 'an empty post is not a call');
assert(Mentions::callsBot('@"The Assistant"#4242', $bot, null), 'the id form needs no username');

echo "ok\n";
