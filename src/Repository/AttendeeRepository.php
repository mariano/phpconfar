<?php
namespace Repository;

use Exception;

class AttendeeRepository extends Repository
{
    public function getTableName()
    {
        return 'attendees';
    }

	public function import(array $config) {
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

	public function findOneByCode($code, $source) {
		$params = array($code);
		$query = 'SELECT * FROM %s WHERE code=?';
		if (!empty($source)) {
			$params[] = $source;
			$query .= ' AND source=?';
		}
		$query .= ' LIMIT 1';
		return $this->db->fetchAssoc(sprintf($query, $this->getTableName()), $params);
	}

	public function findTicket($search) {
		$search = trim($search);
		if (empty($search)) {
			return array();
		}

		$result = $this->db->fetchAll(sprintf('SELECT * FROM %s WHERE code LIKE ? OR email LIKE ? OR first_name LIKE ? OR last_name LIKE ?', $this->getTableName()), array(
			'%' . $search . '%',
			'%' . $search . '%',
			'%' . $search . '%',
			'%' . $search . '%'
		));
		if (empty($result)) {
			return null;
		}
		return $result;
	}
}