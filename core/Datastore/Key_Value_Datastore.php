<?php

namespace Carbon_Fields\Datastore;

use Carbon_Fields\App;
use Carbon_Fields\Helper\Helper;
use Carbon_Fields\Field\Field;
use Carbon_Fields\Value_Set\Value_Set;
use Carbon_Fields\Exception\Incorrect_Syntax_Exception;

/**
 * Key Value Datastore
 * Provides basic functionality to save in a key-value storage
 */
abstract class Key_Value_Datastore extends Datastore {

	/**
	 * Key Toolset for key generation and comparison utilities
	 * 
	 * @var Key_Toolset
	 */
	protected $key_toolset;

	/**
	 * Initialize the datastore.
	 */
	public function __construct() {
		$this->key_toolset = App::resolve( 'key_toolset' );
		parent::__construct();
	}

	/**
	 * Get array of ancestors (ordered top-bottom) with the field name appended to the end
	 *
	 * @param Field $field
	 * @return array<string>
	 */
	protected function get_full_hierarchy_for_field( Field $field ) {
		$full_hierarchy = array_merge( $field->get_hierarchy(), array( $field->get_base_name() ) );
		return $full_hierarchy;
	}

	/**
	 * Get array of ancestor entry indexes (ordered top-bottom)
	 * Indexes show which entry/group this field belongs to in a Complex_Field
	 *
	 * @param Field $field
	 * @return array<int>
	 */
	protected function get_full_hierarchy_index_for_field( Field $field ) {
		$hierarchy_index = $field->get_hierarchy_index();
		$full_hierarchy_index = ! empty( $hierarchy_index ) ? $hierarchy_index : array( 0 );
		return $full_hierarchy_index;
	}

	/**
	 * Raw Value Set Tree schema:
	 * array(
	 *     [field_name] => array(
	 *         'value_set' => [raw_value_set],
	 *         'groups' => array(
	 *             array(
	 *                 [recursion]
	 *             ),
	 *             ...
	 *         ),
	 *     ),
	 *     ...
	 * )
	 *
	 * @param array<stdClass> $storage_array
	 * @return array
	 */
	protected function cascading_storage_array_to_raw_value_set_tree( $storage_array ) {
		$tree = array();

		foreach ( $storage_array as $row ) {
			$parsed_storage_key = $this->key_toolset->parse_storage_key( $row->key );

			if ( $parsed_storage_key['property'] === $this->key_toolset->get_keepalive_property() ) {
				continue;
			}

			$level = &$tree;
			foreach ( $parsed_storage_key['full_hierarchy'] as $i => $field_name ) {
				$index = isset( $parsed_storage_key['hierarchy_index'][ $i ] ) ? $parsed_storage_key['hierarchy_index'][ $i ] : -1;

				if ( ! isset( $level[ $field_name ] ) ) {
					$level[ $field_name ] = array();
				}
				$level = &$level[ $field_name ];

				if ( $i < count( $parsed_storage_key['full_hierarchy'] ) - 1 ) {
					if ( ! isset( $level['groups'] ) ) {
						$level['groups'] = array();
					}
					$level = &$level['groups'];

					if ( ! isset( $level[ $index ] ) ) {
						$level[ $index ] = array();
					}
					$level = &$level[ $index ];
				} else  {
					if ( ! isset( $level['value_set'] ) ) {
						$level['value_set'] = array();
					}
					$level = &$level['value_set'];

					if ( ! isset( $level[ $parsed_storage_key['value_index'] ] ) ) {
						$level[ $parsed_storage_key['value_index'] ] = array();
					}
					$level = &$level[ $parsed_storage_key['value_index'] ];

					$level[ $parsed_storage_key['property'] ] = $row->value;
				}
			}
			$level = &$tree;
		}

		Helper::ksort_recursive( $tree );
		return $tree;
	}

	/**
	 * Get a reduced raw value set tree only relevant to the specified field
	 * 
	 * @param  array $raw_value_set_tree
	 * @param  Field $field
	 * @return array
	 */
	protected function reduce_raw_value_set_tree_to_field( $raw_value_set_tree, Field $field ) {
		$field_raw_value_set_tree = $raw_value_set_tree;
		$hierarchy = $field->get_hierarchy();
		$hierarchy_index = $field->get_hierarchy_index();

		foreach ( $hierarchy as $index => $parent_field ) {
			if ( isset( $field_raw_value_set_tree[ $parent_field ]['groups'][ $hierarchy_index[ $index ] ] ) ) {
				$field_raw_value_set_tree = $field_raw_value_set_tree[ $parent_field ]['groups'][ $hierarchy_index[ $index ] ];
			}
		}

		if ( isset( $field_raw_value_set_tree[ $field->get_base_name() ] ) ) {
			$field_raw_value_set_tree = $field_raw_value_set_tree[ $field->get_base_name() ];
		}

		return $field_raw_value_set_tree;
	}

	/**
	 * Get a raw database query results array for a field
	 *
	 * @param Field $field The field to retrieve value for.
	 * @param array $storage_key_patterns
	 * @return array<stdClass> Array of {key, value} objects
	 */
	abstract protected function get_storage_array( Field $field, $storage_key_patterns );

	/**
	 * Get the field value(s)
	 *
	 * @param Field $field The field to get value(s) for
	 * @return array<array>
	 */
	public function load( Field $field ) {
		$storage_key_patterns = $this->key_toolset->get_storage_key_getter_patterns( $field->is_simple_root_field(), $this->get_full_hierarchy_for_field( $field ) );
		$cascading_storage_array = $this->get_storage_array( $field, $storage_key_patterns );
		$raw_value_set_tree = $this->cascading_storage_array_to_raw_value_set_tree( $cascading_storage_array );
		$raw_value_set_tree = $this->reduce_raw_value_set_tree_to_field( $raw_value_set_tree, $field );
		return $raw_value_set_tree;
	}

	/**
	 * Save a single key-value pair to the database
	 *
	 * @param string $key
	 * @param string $value
	 */
	abstract protected function save_key_value_pair( $key, $value );

	/**
	 * Save the field value(s)
	 *
	 * @param Field $field The field to save.
	 */
	public function save( Field $field ) {
		$value_set = $field->get_full_value();

		if ( empty( $value_set ) && $field->get_value_set()->keepalive() ) {
			$storage_key = $this->key_toolset->get_storage_key(
				$field->is_simple_root_field(),
				$this->get_full_hierarchy_for_field( $field ),
				$this->get_full_hierarchy_index_for_field( $field ),
				0,
				$this->key_toolset->get_keepalive_property()
			);
			$this->save_key_value_pair( $storage_key, '' );
		}
		foreach ( $value_set as $value_group_index => $values ) {
			foreach ( $values as $property => $value ) {
				$storage_key = $this->key_toolset->get_storage_key(
					$field->is_simple_root_field(),
					$this->get_full_hierarchy_for_field( $field ),
					$this->get_full_hierarchy_index_for_field( $field ),
					$value_group_index,
					$property
				);
				$this->save_key_value_pair( $storage_key, $value );
			}
		}
	}
}
