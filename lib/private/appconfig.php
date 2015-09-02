<?php
/**
 * @author Arthur Schiwon <blizzz@owncloud.com>
 * @author Bart Visscher <bartv@thisnet.nl>
 * @author Jakob Sack <mail@jakobsack.de>
 * @author Joas Schilling <nickvergessen@owncloud.com>
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Lukas Reschke <lukas@owncloud.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Robin McCorkell <rmccorkell@karoshi.org.uk>
 * @author Scrutinizer Auto-Fixer <auto-fixer@scrutinizer-ci.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OC;

use OCP\IAppConfig;
use OCP\IDBConnection;

/**
 * This class provides an easy way for apps to store config values in the
 * database.
 */
class AppConfig implements IAppConfig {
	/**
	 * @var \OCP\IDBConnection $conn
	 */
	protected $conn;

	private $cache = array();

	/**
	 * @param IDBConnection $conn
	 */
	public function __construct(IDBConnection $conn) {
		$this->conn = $conn;
		$this->configLoaded = false;
	}

	/**
	 * @param string $app
	 * @return array
	 */
	private function getAppValues($app) {
		$this->loadConfigValues();

		if (isset($this->cache[$app])) {
			return $this->cache[$app];
		}

		return [];
	}

	/**
	 * Get all apps using the config
	 *
	 * @return array an array of app ids
	 *
	 * This function returns a list of all apps that have at least one
	 * entry in the appconfig table.
	 */
	public function getApps() {
		$this->loadConfigValues();

		return $this->getSortedKeys($this->cache);
	}

	/**
	 * Get the available keys for an app
	 *
	 * @param string $app the app we are looking for
	 * @return array an array of key names
	 *
	 * This function gets all keys of an app. Please note that the values are
	 * not returned.
	 */
	public function getKeys($app) {
		$this->loadConfigValues();

		if (isset($this->cache[$app])) {
			return $this->getSortedKeys($this->cache[$app]);
		}

		return [];
	}

	public function getSortedKeys($data) {
		$keys = array_keys($data);
		sort($keys);
		return $keys;
	}

	/**
	 * Gets the config value
	 *
	 * @param string $app app
	 * @param string $key key
	 * @param string $default = null, default value if the key does not exist
	 * @return string the value or $default
	 *
	 * This function gets a value from the appconfig table. If the key does
	 * not exist the default value will be returned
	 */
	public function getValue($app, $key, $default = null) {
		$this->loadConfigValues();

		if ($this->hasKey($app, $key)) {
			return $this->cache[$app][$key];
		}

		return $default;
	}

	/**
	 * check if a key is set in the appconfig
	 *
	 * @param string $app
	 * @param string $key
	 * @return bool
	 */
	public function hasKey($app, $key) {
		$this->loadConfigValues();

		return isset($this->cache[$app][$key]);
	}

	/**
	 * Sets a value. If the key did not exist before it will be created.
	 *
	 * @param string $app app
	 * @param string $key key
	 * @param string $value value
	 * @return bool True if the value was inserted or updated, false if the value was the same
	 */
	public function setValue($app, $key, $value) {
		if (!$this->hasKey($app, $key)) {
			$inserted = (bool) $this->conn->insertIfNotExist('*PREFIX*appconfig', [
				'appid' => $app,
				'configkey' => $key,
				'configvalue' => $value,
			], [
				'appid',
				'configkey',
			]);

			if ($inserted) {
				$this->cache[$app][$key] = $value;
				return true;
			}
		}

		$sql = $this->conn->getQueryBuilder();
		$sql->update('appconfig')
			->set('configvalue', $sql->createParameter('configvalue'))
			->where($sql->expr()->eq('appid', $sql->createParameter('app')))
			->andWhere($sql->expr()->eq('configkey', $sql->createParameter('configkey')))
			->andWhere($sql->expr()->neq('configvalue', $sql->createParameter('configvalue')))
			->setParameter('configvalue', $value)
			->setParameter('app', $app)
			->setParameter('configkey', $key)
			->setParameter('configvalue', $value);
		$changedRow = (bool) $sql->execute();

		$this->cache[$app][$key] = $value;

		return $changedRow;
	}

	/**
	 * Deletes a key
	 *
	 * @param string $app app
	 * @param string $key key
	 * @return boolean|null
	 */
	public function deleteKey($app, $key) {
		$this->loadConfigValues();

		$sql = $this->conn->getQueryBuilder();
		$sql->delete('appconfig')
			->where($sql->expr()->eq('appid', $sql->createParameter('app')))
			->andWhere($sql->expr()->eq('configkey', $sql->createParameter('configkey')))
			->setParameter('app', $app)
			->setParameter('configkey', $key);
		$sql->execute();

		unset($this->cache[$app][$key]);
	}

	/**
	 * Remove app from appconfig
	 *
	 * @param string $app app
	 * @return boolean|null
	 *
	 * Removes all keys in appconfig belonging to the app.
	 */
	public function deleteApp($app) {
		$this->loadConfigValues();

		$sql = $this->conn->getQueryBuilder();
		$sql->delete('appconfig')
			->where($sql->expr()->eq('appid', $sql->createParameter('app')))
			->setParameter('app', $app);
		$sql->execute();

		unset($this->cache[$app]);
	}

	/**
	 * get multiple values, either the app or key can be used as wildcard by setting it to false
	 *
	 * @param string|false $app
	 * @param string|false $key
	 * @return array|false
	 */
	public function getValues($app, $key) {
		if (($app !== false) === ($key !== false)) {
			return false;
		}

		if ($key === false) {
			return $this->getAppValues($app);
		} else {
			$configs = [];
			foreach ($this->getApps() as $appId) {
				if ($this->hasKey($appId, $key)) {
					$configs[$appId] = $this->getValue($appId, $key);
				}
			}

			return $configs;
		}
	}

	/**
	 * Load all the app config values
	 */
	protected function loadConfigValues() {
		if ($this->configLoaded) return;

		$this->cache = [];

		$sql = $this->conn->getQueryBuilder();
		$sql->select('*')
			->from('appconfig');
		$result = $sql->execute();

		while ($row = $result->fetch()) {
			$this->cache[$row['appid']][$row['configkey']] = $row['configvalue'];
		}
		$result->closeCursor();

		$this->configLoaded = true;
	}
}
