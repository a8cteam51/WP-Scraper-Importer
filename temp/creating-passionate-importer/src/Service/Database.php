<?php

/**
 * Database Class
 *
 * Handles the SQLLite database operations for the Wayback Machine to WordPress Scraper plugin.
 */

namespace A8C\SpecialProjects\ScrapperToWP\Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}


/**
 * Class Database
 */
class Database {
	/**
	 * Path to the SQLite database file.
	 *
	 * @var string
	 */
	private $db_path;

	/**
	 * SQLite database connection.
	 *
	 * @var \PDO
	 */
	private $connection;

	public function __construct( $db_path ) {
		$this->db_path = $db_path;
		$this->connect();
	}

	/**
	 * Establish a connection to the SQLite database.
	 *
	 * @return void
	 */
	private function connect() {
		try {
			$this->connection = new \PDO( 'sqlite:' . $this->db_path );
			$this->connection->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		} catch ( \PDOException $e ) {
			error_log( 'Database connection failed: ' . $e->getMessage() );
			throw $e;
		}
	}
	/**
	 * Execute a query on the database.
	 *
	 * @param string $query  The SQL query to execute.
	 * @param array  $params Optional parameters for prepared statements.
	 * @return \PDOStatement|false The result of the query or false on failure.
	 */
	public function query( $query, $params = array() ) {
		try {
			if ( empty( $params ) ) {
				return $this->connection->query( $query );
			} else {
				$stmt = $this->connection->prepare( $query );
				$stmt->execute( $params );
				return $stmt;
			}
		} catch ( \PDOException $e ) {
			error_log( 'Database query failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Execute a query and return the results as an array.
	 *
	 * @param string $query  The SQL query to execute.
	 * @param array  $params Optional parameters for prepared statements.
	 * @return array|false The results of the query or false on failure.
	 */
	public function get_results( $query, $params = array() ) {
		try {
			if ( empty( $params ) ) {
				$stmt = $this->connection->query( $query );
			} else {
				$stmt = $this->connection->prepare( $query );
				$stmt->execute( $params );
			}

			if ( $stmt ) {
				return $stmt->fetchAll( \PDO::FETCH_ASSOC );
			}
			return false;
		} catch ( \PDOException $e ) {
			error_log( 'Database get_results failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Close the database connection.
	 *
	 * @return void
	 */
	public function close() {
		$this->connection = null;
	}
}
