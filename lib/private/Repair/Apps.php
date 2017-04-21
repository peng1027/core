<?php
/**
 * @author Victor Dubiniuk <dubiniuk@aheadworks.com>
 *
 * @copyright Copyright (c) 2017, ownCloud GmbH
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

namespace OC\Repair;

use OC\RepairException;
use OC_App;
use OCP\App\AppAlreadyInstalledException;
use OCP\App\AppManagerException;
use OCP\App\AppNotFoundException;
use OCP\App\AppNotInstalledException;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\Util;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\GenericEvent;

class Apps implements IRepairStep {

	const KEY_COMPATIBLE = 'compatible';
	const KEY_INCOMPATIBLE = 'incompatible';
	const KEY_MISSING = 'missing';

	/** @var  IAppManager */
	private $appManager;

	/** @var  EventDispatcher */
	private $eventDispatcher;

	/**
	 * Apps constructor.
	 *
	 * @param IAppManager $appManager
	 */
	public function __construct(IAppManager $appManager, EventDispatcher $eventDispatcher) {
		$this->appManager = $appManager;
		$this->eventDispatcher = $eventDispatcher;
	}

	/**
	 * @return string
	 */
	public function getName() {
		return 'Put back missing apps';
	}

	/**
	 * @param IOutput $output
	 * @throws RepairException
	 */
	public function run(IOutput $output) {
		$appsToUpgrade = $this->getAppsToUpgrade();
		$isMarketEnabled = $this->appManager->isEnabledForUser('market');
		$failedCompatibleApps = [];
		$failedIncompatibleApps = [];
		$failedMissingApps = [];

		if ($isMarketEnabled) {
			$this->loadApp('market');
			try {
				$failedCompatibleApps = $this->getAppsFromMarket(
					$output,
					$appsToUpgrade[self::KEY_COMPATIBLE],
					'upgradeAppStoreApp'
				);
				$failedIncompatibleApps = $this->getAppsFromMarket(
					$output,
					$appsToUpgrade[self::KEY_INCOMPATIBLE],
					'upgradeAppStoreApp'
				);
				$failedMissingApps = $this->getAppsFromMarket(
					$output,
					$appsToUpgrade[self::KEY_MISSING],
					'reinstallAppStoreApp'
				);
				$hasNotUpdatedCompatibleApps = count($failedCompatibleApps);

				// TODO: find why/where these apps are disabled
				$appsToEnable = array_diff(
					$appsToUpgrade[self::KEY_MISSING],
					$failedMissingApps
				);

				foreach ($appsToEnable as $app){
					$this->appManager->enableApp($app);
				}

			} catch (AppManagerException $e) {
				$output->warning('No connection to marketplace');
				$isMarketEnabled = false;
			}
		}

		// Do not put this in else
		if (!$isMarketEnabled) {
			$hasNotUpdatedCompatibleApps = false;
			$failedMissingApps = $appsToUpgrade[self::KEY_MISSING];
			$failedIncompatibleApps = $appsToUpgrade[self::KEY_INCOMPATIBLE];
		}

		$hasBlockingMissingApps = count($failedMissingApps);
		$hasBlockingIncompatibleApps = count($failedIncompatibleApps);

		if ($hasBlockingIncompatibleApps || $hasBlockingMissingApps) {
			// fail
			$output->warning('You have incompatible or missing apps enabled.');
			$output->warning(
				'please install app manually with tarball or disable them'
				. $this->getOccDisableMessage(array_merge($failedIncompatibleApps, $failedMissingApps))
			);

			throw new RepairException('Upgrade is not possible');
		} elseif ($hasNotUpdatedCompatibleApps) {
			// warn?
		}
	}

	/**
	 * Upgrade appList from market
	 * Return an array of apps that were not upgraded successfully
	 *
	 * @param IOutput $output
	 * @param string[] $appList
	 * @return array
	 * @throws AppManagerException
	 */
	protected function getAppsFromMarket(IOutput $output, $appList, $event) {
		$failedApps = [];
		foreach ($appList as $app) {
			$output->info("Fetching app from market: $app");
			try {
				$this->eventDispatcher->dispatch(
					sprintf('%s::%s', IRepairStep::class, $event),
					new GenericEvent($app)
				);
			} catch (AppAlreadyInstalledException $e) {
				$failedApps[] = $app;
			} catch (AppNotInstalledException $e) {
				$failedApps[] = $app;
			} catch (AppNotFoundException $e) {
				$failedApps[] = $app;
			} catch (AppManagerException $e) {
				// No connection to market. Abort.
				throw $e;
			} catch (\Exception $e) {
				// TODO: check the reason
				$failedApps[] = $app;
				$output->warning(get_class($e));

				$output->warning($e->getMessage());
			}
		}
		return $failedApps;
	}

	/**
	 * Get app list separated as compatible/incompatible/missing
	 *
	 * @return array
	 */
	protected function getAppsToUpgrade() {
		$installedApps = $this->appManager->getInstalledApps();
		$appsToUpgrade = [
			self::KEY_COMPATIBLE => [],
			self::KEY_INCOMPATIBLE => [],
			self::KEY_MISSING => []
		];

		foreach ($installedApps as $appId) {
			$info = $this->appManager->getAppInfo($appId);
			if (!isset($info['id']) || is_null($info['id'])) {
				$appsToUpgrade[self::KEY_MISSING][] = $appId;
				continue;
			}
			$version = Util::getVersion();
			$key = (\OC_App::isAppCompatible($version, $info)) ? self::KEY_COMPATIBLE : self::KEY_INCOMPATIBLE;
			$appsToUpgrade[$key][] = $appId;
		}
		return $appsToUpgrade;
	}

	protected function getOccDisableMessage($appList) {
		if (!count($appList)) {
			return '';
		}
		$appList = array_map(
			function ($appId) {
				return "occ app:disable $appId";
			},
			$appList
		);
		return "\n" . implode("\n", $appList);
	}

	/**
	 * @codeCoverageIgnore
	 * @param string $app
	 */
	protected function loadApp($app) {
		OC_App::loadApp($app, false);
	}
}
