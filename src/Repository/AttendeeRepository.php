<?php
namespace Repository;

use Exception;
use PDO;

class AttendeeRepository extends Repository
{
	public function getTableName()
	{
		return 'attendees';
	}

	public function import(array $config)
	{
		$tickets = array();

		// Import from Evenbrite
		$contents = file_get_contents($config['urls']['evenbrite']);
		if (!empty($contents)) {
			$records = json_decode($contents, true);
			if (!empty($records['attendees'])) {
				foreach($records['attendees'] as $record) {
					if (empty($record['attendee']) || empty($record['attendee']['ticket_id']) || empty($record['attendee']['order_id']) || empty($record['attendee']['quantity'])) {
						continue;
					}

					for($ticket=1; $ticket <= $record['attendee']['quantity']; $ticket++) {
						$tickets[] = array(
							'code' => $record['attendee']['order_id'] . $record['attendee']['id'] . str_pad($ticket, 3, '0', STR_PAD_LEFT),
							'source' => 'evenbrite',
							'email' => $record['attendee']['email'],
							'first_name' => $record['attendee']['first_name'],
							'last_name' => $record['attendee']['last_name'],
							'role' => 'attendee'
						);
					}
				}
			}
		}

		// Import from Eventioz
		$contents = file_get_contents($config['urls']['eventioz']);
		if (!empty($contents)) {
			$records = json_decode($contents, true);
			foreach($records as $i => $record) {
				if (empty($record['registration']) || empty($record['registration']['purchased_at'])) {
					continue;
				}
				$tickets[] = array(
					'code' => $record['registration']['accreditation_code'],
					'source' => 'eventioz',
					'email' => $record['registration']['email'],
					'first_name' => $record['registration']['first_name'],
					'last_name' => $record['registration']['last_name'],
					'role' => 'attendee'
				);
			}
		}

		// Do import

		$imported = 0;
		$ignored = 0;
		foreach($tickets as $i => $ticket) {
			$record = $this->findOneByCode($ticket['code'], $ticket['source']);
			if ($record) {
				$ignored++;
				continue;
			}

			$this->insert($ticket);
			$imported++;
		}

		return compact('imported', 'ignored');
	}

	public function findOneByCode($code, $source)
	{
		$params = array($code);
		$query = 'SELECT * FROM %s WHERE code=?';
		if (!empty($source)) {
			$params[] = $source;
			$query .= ' AND source=?';
		}
		$query .= ' LIMIT 1';
		return $this->db->fetchAssoc(sprintf($query, $this->getTableName()), $params);
	}

	public function tickets()
	{
		return $this->db->fetchAll(sprintf('SELECT * FROM %s WHERE role != ? ORDER BY id, first_name, last_name', $this->getTableName()), array('deleted'));
	}

	public function findTicket($search)
	{
		$search = trim($search);
		if (empty($search)) {
			return array();
		}

		$query = 'SELECT * FROM %s WHERE ';
		$parameters = array();
		foreach(explode(' ', preg_replace('/\s/', ' ', $search)) as $i => $word) {
			$query .= ($i > 0 ? ' OR ' : '') . 'code LIKE ? OR email LIKE ? OR first_name LIKE ? OR last_name LIKE ?';
			$parameters = array_merge($parameters, array(
				'%' . $word . '%',
				'%' . $word . '%',
				'%' . $word . '%',
				'%' . $word . '%'
			));
		}
		$result = $this->db->fetchAll(sprintf($query, $this->getTableName()), $parameters);
		if (empty($result)) {
			return null;
		}
		return $result;
	}

	public function elegible($fields = null, $limit = null)
	{
		if (empty($fields)) {
			$fields = '*';
		} else {
			$fields = implode(',', $fields);
		}
		$query = 'SELECT ' . $fields . ' FROM %s WHERE role=?';
		$parameters = array('attendee');
		$parameterTypes = array(PDO::PARAM_STRING);
		if (!empty($limit)) {
			$query .= ' LIMIT ?';
			$parameters[] = (int) $limit;
			$parameterTypes[] = PDO::PARAM_INT;
		}
		$statement = $this->db->executeQuery(sprintf($query, $this->getTableName()), $parameters, $parameterTypes);
		return $statement->fetchAll();
	}

	public function raffle() {
		return $this->db->fetchAssoc(sprintf('SELECT * FROM attendees WHERE role=? ORDER BY RAND() LIMIT 1'), array('attendee'));
	}
}