<?php
/**
 * Offline ConsentOrder double for consent regression tests.
 *
 * @package AshbiSubscriptions\Tests
 */

/** Isolated local test double; no provider requests. */
class ConsentOrder {
	/** Metadata.
	 *
	 * @var array
	 */
	public $meta = array();
	/** Payment method.
	 *
	 * @var string
	 */
	public $method = '';
	/** Return a fabricated ID.
	 *
	 * @return int
	 */
	public function get_id() {
		return 501; }
	/** Return payment method.
	 *
	 * @return string
	 */
	public function get_payment_method() {
		return $this->method; }
	/** Return method title.
	 *
	 * @return string
	 */
	public function get_payment_method_title() {
		return 'Fixture Stripe'; }
	/** Return metadata.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public function get_meta( $key ) {
		return $this->meta[ $key ] ?? ''; }
	/** Write metadata.
	 *
	 * @param string $key Key.
	 * @param mixed  $value Value.
	 */
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value; }
	/** Set method.
	 *
	 * @param string $method Method.
	 */
	public function set_payment_method( $method ) {
		$this->method = $method; }
	/** Set method title.
	 *
	 * @param string $title Title.
	 */
	public function set_payment_method_title( $title ) {}
}
