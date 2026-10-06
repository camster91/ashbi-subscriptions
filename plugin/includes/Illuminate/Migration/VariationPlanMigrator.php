<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 or PHPUnit path.
/**
 * Offline, no-charge migration of product metadata to exact plan mappings.
 *
 * @package SpringDevs\Subscription
 */

namespace SpringDevs\Subscription\Illuminate\Migration;

use SpringDevs\Subscription\Illuminate\Plans\CachePurge;
use SpringDevs\Subscription\Illuminate\Plans\PlanRepository as Repository;

/** Bounded scan, locked apply and guarded rollback of the last apply. */
class VariationPlanMigrator {
	/** Last report option. */
	const REPORT = 'subscrpt_variation_migration_report';
	/** Last apply ownership journal. */
	const JOURNAL = 'subscrpt_variation_migration_journal';
	/** Visible operational lock; the DB connection lock handles worker death. */
	const LOCK = 'subscrpt_variation_migration_lock';
	/** Bounded report size; split large catalogues with --product. */
	const MAX_ENTITIES = 10000;

	/**
	 * Normalize shared UI and CLI settings.
	 *
	 * @param array $settings Requested settings.
	 * @return array
	 */
	public static function settings( array $settings ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $settings['products'] ?? array() ) ) ) ) );
		sort( $ids );
		return array(
			'group_title'    => sanitize_text_field( $settings['group_title'] ?? 'Subscribe & Save' ),
			'attribute'      => sanitize_title( $settings['attribute'] ?? get_option( 'subscrpt_variation_migration_attribute', '' ) ),
			'products'       => $ids,
			'include_simple' => ! empty( $settings['include_simple'] ),
			'remove_stopgap' => ! empty( $settings['remove_stopgap'] ),
		);
	}

	/**
	 * Generate a deterministic report and fingerprint without writing product data.
	 *
	 * @param array $settings Normalized settings.
	 * @return array
	 * @throws \RuntimeException When the bounded scan cannot complete.
	 */
	public function scan( array $settings ) {
		$group = null;
		foreach ( Repository::get_groups() as $candidate ) {
			if ( 'active' === $candidate['status'] && 1 === (int) $candidate['type'] && $settings['group_title'] === $candidate['title'] ) {
				$group = $candidate;
				break;
			}
		}
		$terms  = $group ? Repository::get_plans( $group['id'] ) : array();
		$report = array(
			'settings' => $settings,
			'group'    => $group,
			'terms'    => $terms,
			'products' => array(),
			'totals'   => array(),
			'errors'   => array(),
		);
		$page   = 1;
		$count  = 0;
		do {
			$args = array(
				'type'    => array( 'simple', 'variable' ),
				'status'  => array( 'publish', 'private', 'draft', 'pending' ),
				'limit'   => 50,
				'page'    => $page,
				'return'  => 'ids',
				'orderby' => 'ID',
				'order'   => 'ASC',
			);
			if ( $settings['products'] ) {
				$args['include'] = $settings['products'];
			}
			$ids = wc_get_products( $args );
			foreach ( $ids as $id ) {
				if ( count( $report['products'] ) >= self::MAX_ENTITIES ) {
					throw new \RuntimeException( 'Scan exceeds 10,000 products. Use --product for smaller batches.' );
				}
				$product = wc_get_product( $id );
				if ( ! $product ) {
					throw new \RuntimeException( 'A scanned product disappeared; scan again.' );
				}
				$variable = $product->is_type( 'variable' );
				$snapshot = array(
					'id'          => (int) $id,
					'variable'    => $variable,
					'mode'        => $product->get_meta( '_subscrpt_variation_term_mode' ),
					'parent_meta' => array(
						'_subscrpt_enabled'               => $product->get_meta( '_subscrpt_enabled' ),
						'_subscrpt_plan_connected_before' => $product->get_meta( '_subscrpt_plan_connected_before' ),
					),
					'entities'    => array(),
					'relations'   => Repository::get_product_connections( $id ),
				);
				foreach ( $variable ? $product->get_children() : array( $id ) as $entity_id ) {
					if ( ++$count > self::MAX_ENTITIES ) {
						throw new \RuntimeException( 'Scan exceeds 10,000 entities. Use --product to scan smaller batches.' );
					}
					$entity = wc_get_product( $entity_id );
					if ( ! $entity ) {
						throw new \RuntimeException( 'A variation disappeared; scan again.' );
					}
					$meta = array();
					foreach ( array( '_subscrpt_enabled', '_subscrpt_timing_per', '_subscrpt_timing_option', '_subscrpt_trial_timing_per', '_subscrpt_trial_timing_option', '_subscrpt_signup_fee', '_subscrpt_limit', '_subscrpt_max_no_payment', '_subscrpt_user_cancel', '_subscrpt_plan_connected_before' ) as $key ) {
						$meta[ $key ] = $entity->get_meta( $key );
					}
					$snapshot['entities'][] = array(
						'id'         => (int) $entity_id,
						'meta'       => $meta,
						'attributes' => $variable ? $entity->get_variation_attributes() : array(),
					);
				}
				$plan = VariationPlanPlanner::plan( $snapshot, $terms, $settings['attribute'], $settings['remove_stopgap'], ! empty( $settings['include_simple'] ) );
				foreach ( $plan['rows'] as $row ) {
					$action                      = $row['action'];
					$report['totals'][ $action ] = ( $report['totals'][ $action ] ?? 0 ) + 1;
				}
				$report['products'][] = $plan;
			}
			++$page;
			$batch_count = count( $ids );
		} while ( 50 === $batch_count );
		if ( $settings['products'] && array_diff( $settings['products'], array_column( array_column( $report['products'], 'product' ), 'id' ) ) ) {
			throw new \RuntimeException( 'Some requested products are unavailable or unsupported. Check --product IDs.' );
		}
		$report['fingerprint'] = hash( 'sha256', wp_json_encode( $report ) );
		return $report;
	}

	/**
	 * Persist a redacted catalogue-only report in a non-autoloaded option.
	 *
	 * @param array  $report Report.
	 * @param string $mode Mode.
	 * @return array
	 * @throws \RuntimeException On a failed persistence check.
	 */
	public function save_report( array $report, $mode ) {
		$report['mode']      = $mode;
		$report['timestamp'] = gmdate( 'c' );
		$report['site_url']  = site_url();
		self::store_option( self::REPORT, $report );
		subscrpt_write_log( 'Variation plan migration: ' . $mode . '; catalogue entities: ' . array_sum( $report['totals'] ?? array() ) . '.' );
		return $report;
	}

	/**
	 * Acquire a connection-scoped mutex and leave an observable option lock.
	 *
	 * @return string DB lock name.
	 * @throws \RuntimeException When another worker owns the lock.
	 */
	public static function acquire_lock() {
		global $wpdb;
		$name = 'ashbi-vpm-' . md5( $wpdb->prefix . self::LOCK );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-scoped mutex.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) ) {
			throw new \RuntimeException( 'Another variation mapping operation is running. Try again when it finishes.' );
		}
		// A dead connection releases GET_LOCK; replace only its stale display option.
		update_option( self::LOCK, array( 'started' => gmdate( 'c' ) ), false );
		return $name;
	}

	/**
	 * Release both forms of the lock.
	 *
	 * @param string $name DB lock name.
	 * @return void
	 */
	public static function release_lock( $name ) {
		global $wpdb;
		delete_option( self::LOCK );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-scoped mutex.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	/**
	 * Apply only after re-planning under the shared lock and comparing the digest.
	 *
	 * @param array  $settings Normalized settings.
	 * @param string $fingerprint Reviewed dry-run fingerprint.
	 * @return array
	 * @throws \RuntimeException On stale review or any failed write.
	 * @throws \Throwable On a runtime persistence failure.
	 */
	public function apply( array $settings, $fingerprint ) {
		$lock   = self::acquire_lock();
		$report = array();
		try {
			$report = $this->scan( $settings );
			if ( ! $fingerprint || ! hash_equals( $report['fingerprint'], $fingerprint ) ) {
				throw new \RuntimeException( 'Catalogue or settings changed since the dry run. Scan again before applying.' );
			}
			$this->begin();
			$journal = array(
				'relations' => array(),
				'terms'     => array(),
				'group'     => null,
				'meta'      => array(),
				'removed'   => array(),
				'products'  => array(),
				'timestamp' => gmdate( 'c' ),
			);
			try {
				$group_id = $report['group'] ? (int) $report['group']['id'] : 0;
				$term_ids = array();
				foreach ( $report['products'] as $plan ) {
					if ( $plan['blocked'] || ! $plan['mapped'] ) {
						continue;
					}
					$id                    = $plan['product']['id'];
					$journal['products'][] = $id;
					foreach ( $plan['rows'] as $row ) {
						if ( ! in_array( $row['action'], array( 'would_link', 'would_create_term', 'already_linked' ), true ) ) {
							continue;
						}
						if ( ! $group_id ) {
							$group_id = Repository::insert_group(
								array(
									'title'        => $settings['group_title'],
									'type'         => 1,
									'product_type' => 1,
									'status'       => 'active',
									'data'         => array(),
								)
							);
							self::require_write( $group_id );
							$journal['group'] = Repository::get_group( $group_id );
						}
						$key     = $row['frequency'] . ':' . $row['interval'] . ':' . $row['trial'] . ':' . $row['trial_interval'] . ':' . $row['signup_fee'];
						$term_id = $row['plan_id'] ? $row['plan_id'] : ( $term_ids[ $key ] ?? 0 );
						if ( ! $term_id ) {
							$term_id = Repository::insert_plan(
								array(
									'plan_group_id'     => $group_id,
									'title'             => FrequencyParser::title( $row['frequency'], $row['interval'] ),
									'type'              => 1,
									'billing_frequency' => $row['frequency'],
									'billing_interval'  => $row['interval'],
									'billing_length'    => 0,
									'free_trial'        => $row['trial'],
									'signup_fee'        => array( 'amount' => $row['signup_fee'] ),
									'status'            => 'active',
									'data'              => array( 'free_trial_interval' => $row['trial_interval'] ),
								)
							);
							self::require_write( $term_id );
							$journal['terms'][] = Repository::get_plan( $term_id );
							$term_ids[ $key ]   = $term_id;
						}
						if ( 'already_linked' !== $row['action'] ) {
							// Create-only: repository upserts must never overwrite a changed mapping.
							if ( Repository::find_relation( $term_id, $id, $row['variation_id'] ) ) {
								throw new \RuntimeException( 'A relation changed during apply; scan again.' );
							}
							$relation_id = Repository::insert_relation(
								array(
									'plan_id' => $term_id,
									'oid'     => $id,
									'vid'     => $row['variation_id'],
									'type'    => Repository::REL_PRODUCT,
									'status'  => 'active',
									'exclude' => 0,
									'data'    => array( 'price_source' => $row['variation_id'] ? 'variation' : 'product' ),
								)
							);
							self::require_write( $relation_id );
							$journal['relations'][] = Repository::get_relation( $relation_id );
						}
						foreach ( array_unique( array( $id, $row['entity_id'] ) ) as $entity_id ) {
							$this->set_meta( $entity_id, '_subscrpt_enabled', 'yes', $journal );
							$this->set_meta( $entity_id, '_subscrpt_plan_connected_before', 'yes', $journal );
						}
					}
					if ( $plan['product']['variable'] ) {
						$this->set_meta( $id, '_subscrpt_variation_term_mode', 'yes', $journal );
					}
					if ( $plan['remove_stopgap'] ) {
						foreach ( $plan['stopgap'] as $relation ) {
							$journal['removed'][] = Repository::get_relation( $relation['relation_id'] );
							self::require_write( Repository::delete_relation( $relation['relation_id'] ) );
						}
					}
				}
				if ( $journal['relations'] || $journal['meta'] || $journal['removed'] ) {
					self::store_option( self::JOURNAL, $journal );
				}
				$report['created'] = $journal;
				$report            = $this->save_report( $report, 'apply' );
				$this->transaction( 'COMMIT' );
			} catch ( \Throwable $error ) {
				$this->transaction( 'ROLLBACK' );
				$this->clear_caches( $report );
				throw $error;
			}
			$this->clear_caches( $report );
			$report['cache_purged'] = self::purge_touched_products( $journal['products'] );
			return $this->save_report( $report, 'apply' );
		} finally {
			self::release_lock( $lock );
		}
	}

	/**
	 * Undo exactly the last apply, only while every owned value remains unchanged.
	 *
	 * @param bool $apply Default false: report only.
	 * @return array
	 * @throws \RuntimeException When ownership is no longer safe or a write fails.
	 * @throws \Throwable On a runtime persistence failure.
	 */
	public function rollback( $apply = false ) {
		global $wpdb;
		$lock = self::acquire_lock();
		try {
			$journal = get_option( self::JOURNAL, array() );
			if ( ! $journal || ! empty( $journal['rolled_back'] ) ) {
				throw new \RuntimeException( 'No reversible last apply is available.' );
			}
			$errors = array();
			$owned  = array_column( $journal['relations'], 'id' );
			foreach ( $journal['relations'] as $expected ) {
				if ( Repository::get_relation( $expected['id'] ) !== $expected ) {
					$errors[] = 'Created relation changed; rollback refused.';
				}
			}
			foreach ( $journal['terms'] as $expected ) {
				if ( Repository::get_plan( $expected['id'] ) !== $expected ) {
					$errors[] = 'Created term changed; rollback refused.';
				}
				foreach ( Repository::get_relations( $expected['id'] ) as $relation ) {
					if ( ! in_array( $relation['id'], $owned, true ) ) {
						$errors[] = 'Created term has new connections; rollback refused.';
					}
				}
			}
			if ( $journal['group'] ) {
				if ( Repository::get_group( $journal['group']['id'] ) !== $journal['group'] || array_column( Repository::get_plans( $journal['group']['id'] ), 'id' ) !== array_column( $journal['terms'], 'id' ) ) {
					$errors[] = 'Created group changed; rollback refused.';
				}
			}
			foreach ( $journal['meta'] as $meta ) {
				if ( get_post_meta( $meta['id'], $meta['key'], true ) !== $meta['after'] ) {
					$errors[] = 'Product mapping metadata changed; rollback refused.';
				}
			}
			foreach ( $journal['removed'] as $removed ) {
				if ( Repository::get_relation( $removed['id'] ) || Repository::find_relation( $removed['plan_id'], $removed['oid'], $removed['vid'], $removed['type'] ) || ! Repository::get_plan( $removed['plan_id'] ) ) {
					$errors[] = 'Removed stopgap cannot be restored safely; rollback refused.';
				}
			}
			$report = array(
				'totals'  => array(
					'would_remove_relations' => count( $journal['relations'] ),
					'would_remove_terms'     => count( $journal['terms'] ),
					'would_restore_meta'     => count( $journal['meta'] ),
					'would_restore_stopgap'  => count( $journal['removed'] ),
				),
				'errors'  => array_values( array_unique( $errors ) ),
				'created' => $journal,
			);
			if ( ! $apply || $errors ) {
				return $this->save_report( $report, 'rollback_dry_run' );
			}
			$this->begin();
			$committed = false;
			try {
				foreach ( $journal['relations'] as $relation ) {
					self::require_write( Repository::delete_relation( $relation['id'] ) );
				}
				foreach ( $journal['terms'] as $term ) {
					self::require_write( Repository::delete_plan( $term['id'] ) );
				}
				if ( $journal['group'] ) {
					self::require_write( Repository::delete_group( $journal['group']['id'] ) );
				}
				foreach ( $journal['removed'] as $relation ) {
					$relation['data'] = wp_json_encode( $relation['data'] );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Exact owned stopgap restoration, preserving its id.
					self::require_write( $wpdb->insert( Repository::relation_table(), $relation ) );
				}
				foreach ( array_reverse( $journal['meta'] ) as $meta ) {
					$product = wc_get_product( $meta['id'] );
					if ( ! $product ) {
						throw new \RuntimeException( 'Product disappeared; rollback refused.' );
					}
					if ( $meta['exists'] ) {
						$product->update_meta_data( $meta['key'], $meta['before'] );
					} else {
						$product->delete_meta_data( $meta['key'] );
					}
					$product->save_meta_data();
					wp_cache_delete( $meta['id'], 'post_meta' );
					self::require_write( $meta['exists'] ? get_post_meta( $meta['id'], $meta['key'], true ) === $meta['before'] : ! metadata_exists( 'post', $meta['id'], $meta['key'] ) );
				}
				$journal['rolled_back'] = gmdate( 'c' );
				self::store_option( self::JOURNAL, $journal );
				$report = $this->save_report( $report, 'rollback_apply' );
				$this->transaction( 'COMMIT' );
				$committed = true;
			} catch ( \Throwable $error ) {
				$this->transaction( 'ROLLBACK' );
				throw $error;
			} finally {
				foreach ( $journal['products'] as $id ) {
					Repository::flush_cache( $id );
					wc_delete_product_transients( $id );
				}
				foreach ( $journal['meta'] as $meta ) {
					wp_cache_delete( $meta['id'], 'post_meta' );
					$product = wc_get_product( $meta['id'] );
					if ( $product ) {
						$product->read_meta_data( true );
					}
				}
				wp_cache_delete( self::JOURNAL, 'options' );
				wp_cache_delete( self::REPORT, 'options' );
			}
			if ( $committed ) {
				$report['cache_purged'] = self::purge_touched_products( $journal['products'] );
				$report                 = $this->save_report( $report, 'rollback_apply' );
			}
			return $report;
		} finally {
			self::release_lock( $lock );
		}
	}

	/**
	 * Change only allowed metadata, preserving exact prior existence and value.
	 *
	 * @param int    $id Entity id.
	 * @param string $key Meta key.
	 * @param string $value New value.
	 * @param array  $journal Ownership journal, by reference.
	 * @return void
	 */
	private function set_meta( $id, $key, $value, array &$journal ) {
		$before = get_post_meta( $id, $key, true );
		if ( $before === $value ) {
			return;
		}
		$journal['meta'][] = array(
			'id'     => $id,
			'key'    => $key,
			'exists' => metadata_exists( 'post', $id, $key ),
			'before' => $before,
			'after'  => $value,
		);
		$product           = wc_get_product( $id );
		$product->update_meta_data( $key, $value );
		$product->save_meta_data();
		wp_cache_delete( $id, 'post_meta' );
		self::require_write( get_post_meta( $id, $key, true ) === $value );
	}

	/**
	 * Run an editor mutation with the same storage and rollback guarantees.
	 * Caller owns the migration mutex and cache cleanup.
	 *
	 * @param callable $operation Mutation.
	 * @return mixed
	 * @throws \Throwable On a runtime persistence failure.
	 */
	public function atomic( $operation ) {
		$this->begin();
		try {
			$result = $operation();
			$this->transaction( 'COMMIT' );
			return $result;
		} catch ( \Throwable $error ) {
			$this->transaction( 'ROLLBACK' );
			throw $error;
		}
	}

	/**
	 * Require transactional storage before touching plan rows or product metadata.
	 *
	 * @return void
	 * @throws \RuntimeException On unsupported storage.
	 */
	private function begin() {
		global $wpdb;
		foreach ( array( Repository::group_table(), Repository::plan_table(), Repository::relation_table(), $wpdb->postmeta, $wpdb->options ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Storage safety preflight.
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
			if ( 'innodb' !== strtolower( (string) $engine ) ) {
				throw new \RuntimeException( 'Apply requires InnoDB plan, metadata and option tables. No changes made.' );
			}
		}
		$this->transaction( 'START TRANSACTION' );
	}

	/**
	 * Execute a fixed transaction command.
	 *
	 * @param string $command Fixed SQL command.
	 * @return void
	 */
	private function transaction( $command ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Internal fixed transaction statements only.
		self::require_write( false !== $wpdb->query( $command ) );
	}

	/**
	 * Fail closed on a database write failure.
	 *
	 * @param mixed $success Write result.
	 * @return void
	 * @throws \RuntimeException On failure.
	 */
	private static function require_write( $success ) {
		if ( ! $success ) {
			throw new \RuntimeException( 'Migration persistence failed. Changes were rolled back; scan again.' );
		}
	}

	/**
	 * Persist a non-autoloaded option and verify even a no-change update.
	 *
	 * @param string $name Option name.
	 * @param array  $value Value.
	 * @return void
	 */
	private static function store_option( $name, array $value ) {
		update_option( $name, $value, false );
		wp_cache_delete( $name, 'options' );
		self::require_write( get_option( $name ) === $value );
	}

	/**
	 * Clear transactional object caches after either commit or rollback.
	 *
	 * @param array $report Report.
	 * @return void
	 */
	private function clear_caches( array $report ) {
		foreach ( $report['products'] ?? array() as $plan ) {
			Repository::flush_cache( $plan['product']['id'] );
			if ( function_exists( 'clean_post_cache' ) ) {
				clean_post_cache( $plan['product']['id'] );
			}
			foreach ( array_merge( array( array( 'id' => $plan['product']['id'] ) ), $plan['product']['entities'] ) as $entity ) {
				wp_cache_delete( $entity['id'], 'post_meta' );
				wc_delete_product_transients( $entity['id'] );
				$product = wc_get_product( $entity['id'] );
				if ( $product ) {
					$product->read_meta_data( true );
				}
			}
		}
		wp_cache_delete( self::JOURNAL, 'options' );
		wp_cache_delete( self::REPORT, 'options' );
	}

	/**
	 * Drop object/page caches for parent products after a successful mapping commit.
	 *
	 * @param array $ids Parent product ids.
	 * @return int
	 */
	private static function purge_touched_products( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		foreach ( $ids as $id ) {
			if ( function_exists( 'clean_post_cache' ) ) {
				clean_post_cache( $id );
			}
			wc_delete_product_transients( $id );
		}
		return CachePurge::products( $ids );
	}
}
