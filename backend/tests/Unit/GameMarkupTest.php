<?php

namespace Tests\Unit;

use App\Domain\Localization\GameMarkup;
use PHPUnit\Framework\TestCase;

class GameMarkupTest extends TestCase
{
    public function test_english_is_kept_as_it_appears_on_screen(): void
    {
        $this->assertSame('Press to browse the shop menu', GameMarkup::source('Press ~INPUT_SHOP_BUY|UrgentPulse~ to browse the shop menu'));
        $this->assertSame('Go to the gun shop. Buy a pistol', GameMarkup::source('Go to the ~BLIP_GUN~ ~b~gun shop~s~.~n~Buy a pistol'));
        $this->assertSame("We're done here", GameMarkup::source("~sl:0.05~We're done ~tone~here"));
        $this->assertSame('Bold text', GameMarkup::source('<b>Bold</b> text'));
        $this->assertSame('No codes at all', GameMarkup::source('No codes at all'));
    }

    public function test_arabic_shows_a_button_mark_and_real_line_breaks(): void
    {
        $this->assertSame('اضغط '.GameMarkup::BUTTON.' لتصفح قائمة الورشة', GameMarkup::target('اضغط ~INPUT_SHOP_BUY|UrgentPulse~ لتصفح قائمة الورشة'));
        $this->assertSame("روح لمحل السلاح.\nاشتر مسدس", GameMarkup::target('روح ~BLIP_GUN~ لمحل ~b~السلاح~s~.~n~اشتر مسدس'));
    }

    public function test_a_timed_line_becomes_one_entry_per_subtitle(): void
    {
        $pairs = GameMarkup::segments(
            "~sl:0:3.02~Rent's just a bullshit social construct anyways, my guy.~sl:0~Point is, fuck Brian.",
            '~sl:0:3.02~الإيجار أصلاً كلام زق.~sl:0~المهم، يلعن أبو برايان.');
        $this->assertSame([["Rent's just a bullshit social construct anyways, my guy.", 'الإيجار أصلاً كلام زق.'], ['Point is, fuck Brian.', 'المهم، يلعن أبو برايان.']], $pairs);
        $this->assertCount(1, GameMarkup::segments('~sl:0~One.~sl:1~Two.', '~sl:0~واحد واثنين.'), 'unequal segments stay one line');
        $this->assertSame(42, GameMarkup::lineId(GameMarkup::segmentId(42, 3)));
        $this->assertSame(42, GameMarkup::lineId(GameMarkup::segmentId(42, 0)));
    }
}
