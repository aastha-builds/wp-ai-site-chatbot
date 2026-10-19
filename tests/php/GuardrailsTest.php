<?php
/**
 * Tests for the rate limiter and lead intent rules.
 *
 * @package SiteSage
 */

use PHPUnit\Framework\TestCase;
use SiteSage\Lead_Intent;
use SiteSage\Rate_Limiter;

class GuardrailsTest extends TestCase {

	private $now = 1000;

	protected function setUp(): void {
		SiteSage_Test_State::reset();
	}

	private function limiter( $limit = 3, $window = 60 ) {
		$test = $this;
		return new Rate_Limiter(
			'ask',
			$limit,
			$window,
			function () use ( $test ) {
				return $test->now;
			}
		);
	}

	public function test_rate_limit_blocks_after_the_limit_and_resets() {
		$limiter = $this->limiter();
		$this->assertTrue( $limiter->hit( '203.0.113.5' ) );
		$this->assertTrue( $limiter->hit( '203.0.113.5' ) );
		$this->assertTrue( $limiter->hit( '203.0.113.5' ) );
		$this->assertFalse( $limiter->hit( '203.0.113.5' ) );
		$this->assertTrue( $limiter->hit( '198.51.100.7' ), 'Other visitors are not affected.' );

		$this->now += 30;
		$this->assertSame( 30, $limiter->retry_after( '203.0.113.5' ) );

		$this->now += 31;
		$this->assertTrue( $limiter->hit( '203.0.113.5' ), 'A new window starts.' );
	}

	public function test_raw_ip_is_not_stored() {
		$this->limiter()->hit( '203.0.113.5' );
		$this->assertStringNotContainsString( '203.0.113.5', implode( ' ', array_keys( SiteSage_Test_State::$transients ) ) );
	}

	public function test_client_ip_uses_remote_addr_only() {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.9';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
		$this->assertSame( '203.0.113.9', Rate_Limiter::client_ip() );
		$_SERVER['REMOTE_ADDR'] = 'garbage';
		$this->assertSame( '0.0.0.0', Rate_Limiter::client_ip() );
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	public function test_intent_words() {
		$words = Lead_Intent::parse_words( 'Book, appointment , PRICE,, pricing' );
		$this->assertSame( array( 'book', 'appointment', 'price', 'pricing' ), $words );

		$this->assertTrue( Lead_Intent::detect( 'Can I book a cleaning?', $words ) );
		$this->assertTrue( Lead_Intent::detect( 'Booking for Saturday', $words ) );
		$this->assertTrue( Lead_Intent::detect( 'What are your prices?', $words ) );
		$this->assertTrue( Lead_Intent::detect( 'I need an APPOINTMENT', $words ) );
		$this->assertFalse( Lead_Intent::detect( 'Do you have a facebook page?', $words ) );
		$this->assertFalse( Lead_Intent::detect( 'Is there parking?', $words ) );
	}

	public function test_should_ask_after_two_questions_or_on_intent() {
		$this->assertFalse( Lead_Intent::should_ask( 1, false, 2, false ) );
		$this->assertTrue( Lead_Intent::should_ask( 2, false, 2, false ) );
		$this->assertTrue( Lead_Intent::should_ask( 1, true, 2, false ) );
		$this->assertFalse( Lead_Intent::should_ask( 5, true, 2, true ), 'Never ask twice.' );
		$this->assertFalse( Lead_Intent::should_ask( 9, false, 0, false ), '0 disables the count rule.' );
	}
}
