<?php
/**
 * Tests for lead validation, CSV export and the Google Sheets payload.
 *
 * @package SiteSage
 */

use PHPUnit\Framework\TestCase;
use SiteSage\Lead_Store;
use SiteSage\Leads_Export;
use SiteSage\Sheets_Webhook;

class LeadsTest extends TestCase {

	private function raw( array $override = array() ) {
		return array_merge(
			array(
				'name'     => '  Jordan Avery ',
				'phone'    => '(415) 555-0107',
				'email'    => 'jordan@example.com',
				'question' => 'How much is whitening?',
				'page_url' => 'https://brightsmile.test/pricing/',
			),
			$override
		);
	}

	protected function setUp(): void {
		SiteSage_Test_State::reset();
	}

	public function test_valid_lead_is_cleaned() {
		$lead = Lead_Store::validate( $this->raw( array( 'name' => '<b>Jordan</b> Avery' ) ) );
		$this->assertSame( 'Jordan Avery', $lead['name'] );
		$this->assertSame( 'jordan@example.com', $lead['email'] );
	}

	public function test_invalid_leads_are_rejected() {
		$this->assertSame( 'sitesage_lead_name', Lead_Store::validate( $this->raw( array( 'name' => 'J' ) ) )->get_error_code() );
		$this->assertSame( 'sitesage_lead_email', Lead_Store::validate( $this->raw( array( 'email' => 'nope' ) ) )->get_error_code() );
		$this->assertSame( 'sitesage_lead_phone', Lead_Store::validate( $this->raw( array( 'phone' => '12' ) ) )->get_error_code() );
		$this->assertSame( 'sitesage_lead_phone', Lead_Store::validate( $this->raw( array( 'phone' => 'call me maybe 5550107' ) ) )->get_error_code() );
	}

	public function test_csv_neutralizes_formulas() {
		$this->assertSame( "'=HYPERLINK(\"x\")", Leads_Export::cell( '=HYPERLINK("x")' ) );
		$this->assertSame( "'+1 415", Leads_Export::cell( '+1 415' ) );
		$this->assertSame( "'@SUM(A1)", Leads_Export::cell( '@SUM(A1)' ) );
		$this->assertSame( 'Jordan', Leads_Export::cell( 'Jordan' ) );

		$csv = Leads_Export::to_csv(
			array(
				array(
					'id'             => 7,
					'created_at'     => '2026-10-19 10:00:00',
					'name'           => 'Avery, Jordan',
					'phone'          => '(415) 555-0107',
					'email'          => 'jordan@example.com',
					'question'       => 'Say "hi"',
					'page_url'       => '',
					'webhook_status' => 'sent',
				),
			)
		);
		$lines = explode( "\n", trim( $csv ) );
		$this->assertSame( 'ID,"Date (UTC)",Name,Phone,Email,Question,Page,"Google Sheets"', $lines[0] );
		$this->assertSame( '7,"2026-10-19 10:00:00","Avery, Jordan","(415) 555-0107",jordan@example.com,"Say ""hi""",,sent', $lines[1] );
	}

	public function test_webhook_payload_and_skip() {
		$hook    = new Sheets_Webhook( 'https://script.google.com/macros/s/abc/exec', 's3cret' );
		$payload = $hook->payload( Lead_Store::validate( $this->raw() ) );
		$this->assertSame( 's3cret', $payload['secret'] );
		$this->assertSame( 'Jordan Avery', $payload['name'] );
		$this->assertSame( 'https://brightsmile.test/', $payload['site'] );

		$this->assertSame( 'skipped', ( new Sheets_Webhook( '', '' ) )->send( $payload ) );
		$this->assertSame( 'skipped', ( new Sheets_Webhook( 'http://insecure.test', '' ) )->send( $payload ) );

		SiteSage_Test_State::$http[] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"ok":true}',
		);
		$this->assertSame( 'sent', $hook->send( Lead_Store::validate( $this->raw() ) ) );
		$this->assertSame( 'failed', $hook->send( Lead_Store::validate( $this->raw() ) ), 'A transport error counts as failed.' );
	}
}
