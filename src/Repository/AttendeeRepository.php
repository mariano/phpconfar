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
        $tickets = [];

        $ticketPrices = [];
        foreach (['evenbrite', 'eventioz'] as $vendor) {
            if (empty($config['prices_' . $vendor])) {
                continue;
            }
            foreach ($config['prices_' . $vendor] as $ticketType => $prices) {
                foreach (explode(',', preg_replace('/\s*/', '', $prices)) as $price) {
                    $ticketPrices[$vendor][floatval($price)] = $ticketType;
                }
            }
        }
        $ticketTypes = [];
        foreach (['evenbrite', 'eventioz'] as $vendor) {
            if (empty($config['tickets_' . $vendor])) {
                continue;
            }
            foreach ($config['tickets_' . $vendor] as $ticketType => $ids) {
                foreach (explode(',', preg_replace('/\s*/', '', $ids)) as $id) {
                    $ticketTypes[$vendor][$id] = $ticketType;
                }
            }
        }

        $prices = [];

        // Import from Evenbrite
        $url = $config['urls']['evenbrite'];
        $url .= (strpos($url, '?') !== false ? '&' : '?') . 'status=attending';
        $contents = file_get_contents($url);
        if (!empty($contents)) {
            $records = json_decode($contents, true);
            if (!empty($records['attendees'])) {
                foreach($records['attendees'] as $record) {
                    if (empty($record['attendee']) || empty($record['attendee']['ticket_id']) || empty($record['attendee']['order_id']) || empty($record['attendee']['quantity'])) {
                        continue;
                    }

                    $price = floatval($record['attendee']['amount_paid']) / $record['attendee']['quantity'];
                    for ($ticket=1; $ticket <= $record['attendee']['quantity']; $ticket++) {
                        $tickets[] = [
                            'code' => $record['attendee']['order_id'] . $record['attendee']['id'] . str_pad($ticket, 3, '0', STR_PAD_LEFT),
                            'source' => 'evenbrite',
                            'email' => $record['attendee']['email'],
                            'first_name' => $record['attendee']['first_name'],
                            'last_name' => $record['attendee']['last_name'],
                            'role' => 'attendee',
                            'ticket_type' => isset($ticketTypes['evenbrite'][$record['attendee']['ticket_id']]) ? $ticketTypes['evenbrite'][$record['attendee']['ticket_id']] : 'conference'
                        ];
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
                $price = floatval($record['registration']['amount']);
                $tickets[] = [
                    'code' => $record['registration']['accreditation_code'],
                    'source' => 'eventioz',
                    'email' => $record['registration']['email'],
                    'first_name' => $record['registration']['first_name'],
                    'last_name' => $record['registration']['last_name'],
                    'role' => 'attendee',
                    'ticket_type' => isset($ticketPrices['eventioz'][$price]) ? $ticketPrices['eventioz'][$price] : 'conference'
                ];
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
        $params = [$code];
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
        return $this->db->fetchAll(sprintf('SELECT * FROM %s WHERE role != ? ORDER BY id, first_name, last_name', $this->getTableName()), ['deleted']);
    }

    public function findTicket($search)
    {
        $search = trim($search);
        if (empty($search)) {
            return [];
        }

        $query = 'SELECT * FROM %s WHERE ';
        $parameters = [];
        foreach(explode(' ', preg_replace('/\s/', ' ', $search)) as $i => $word) {
            $query .= ($i > 0 ? ' OR ' : '') . 'code LIKE ? OR email LIKE ? OR first_name LIKE ? OR last_name LIKE ?';
            $parameters = array_merge($parameters, [
                '%' . $word . '%',
                '%' . $word . '%',
                '%' . $word . '%',
                '%' . $word . '%'
            ]);
        }
        $result = $this->db->fetchAll(sprintf($query, $this->getTableName()), $parameters);
        if (empty($result)) {
            return null;
        }
        return $result;
    }

    public function raffle(array $roles)
    {
        $seed = rand();
        $query = 'SELECT * FROM %s WHERE role IN (';
        foreach(array_values($roles) as $i => $role) {
            $query .= ($i > 0 ? ', ' : '') . '?';
            $parameters[] = $role;
            $parameterTypes[] = PDO::PARAM_STR;
        }
        $query .= ') ORDER BY RAND('.$seed.') LIMIT 1';

        $statement = $this->db->executeQuery(sprintf($query, $this->getTableName()), $parameters, $parameterTypes);
        $result = $statement->fetchAll();
        if (empty($result)) {
            return null;
        }

        $attendee = $result[0];
        $this->update(['raffled' => 1], ['id' => $attendee['id']]);
        return $attendee;
    }

    public function findForRaffle(array $roles, $fields = null, $limit = null)
    {
        if (empty($fields)) {
            $fields = '*';
        } else {
            $fields = implode(',', $fields);
        }

        $parameters = [];
        $parameterTypes = [];

        $query = 'SELECT ' . $fields . ' FROM %s WHERE raffled = 0 AND role IN (';

        foreach(array_values($roles) as $i => $role) {
            $query .= ($i > 0 ? ', ' : '') . '?';
            $parameters[] = $role;
            $parameterTypes[] = PDO::PARAM_STR;
        }

        $query .= ')';

        if (!empty($limit)) {
            $query .= ' LIMIT ?';
            $parameters[] = (int) $limit;
            $parameterTypes[] = PDO::PARAM_INT;
        }

        $statement = $this->db->executeQuery(sprintf($query, $this->getTableName()), $parameters, $parameterTypes);
        return $statement->fetchAll();
    }

}