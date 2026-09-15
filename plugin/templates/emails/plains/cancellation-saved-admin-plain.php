<?php
/**
 * Admin notice: a subscription was saved (free), plain text.
 *
 * @var string $email_heading Email heading.
 * @var string $intro         Opening sentence.
 * @var string $reason        Reason the customer gave, may be empty.
 * @var string $reported_at   When this was reported.
 * @var string $reports_url   Admin Reports page.
 *
 * @package SpringDevs\Subscription
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( $email_heading ) . " =\n\n";
echo esc_html( $intro ) . "\n\n";
echo esc_html__( 'Reason they had selected:', 'subscription' ) . ' ' . esc_html( '' !== $reason ? $reason : __( 'None selected', 'subscription' ) ) . "\n";
echo esc_html__( 'Reported:', 'subscription' ) . ' ' . esc_html( $reported_at ) . "\n\n";
echo esc_html__( 'Reports:', 'subscription' ) . ' ' . esc_url_raw( $reports_url ) . "\n";
