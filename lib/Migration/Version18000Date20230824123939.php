<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2023, Joas Schilling <coding@schilljs.com>
 *
 * @author Joas Schilling <coding@schilljs.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Talk\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version18000Date20230824123939 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$modified = false;
		$table = $schema->getTable('talk_bots_server');
		if (!$table->hasIndex('talk_bots_server_urlhash')) {
			$table->addUniqueIndex(['url_hash'], 'talk_bots_server_urlhash');
			$modified = true;
		}
		if (!$table->hasIndex('talk_bots_server_secret')) {
			$table->addUniqueIndex(['secret'], 'talk_bots_server_secret');
			$modified = true;
		}

		$table = $schema->getTable('talk_bots_conversation');
		if (!$table->hasIndex('talk_bots_convo_uniq')) {
			$table->addUniqueIndex(['bot_id', 'token'], 'talk_bots_convo_uniq');
			$modified = true;
		}

		return $modified ? $schema : null;
	}
}
