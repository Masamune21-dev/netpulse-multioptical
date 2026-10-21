<?php

namespace App\Http\Controllers;

use App\Support\ViewerDummyData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MapApiController extends Controller
{
    /** Port data older than this (poller runs every minute) no longer reflects the link. */
    private const LINK_STALE_AFTER_MINUTES = 10;

    public function nodes(Request $request)
    {
        if (ViewerDummyData::isViewer($request)) {
            if ($request->method() !== 'GET') {
                return response()->json(['success' => false, 'error' => 'Forbidden'], 403);
            }
            $with = (int) $request->query('with_interfaces', 0) === 1;
            return response()->json(ViewerDummyData::mapNodes($with));
        }

        $this->ensureMapTables();

        switch ($request->method()) {
            case 'GET':
                return $this->getNodes();
            case 'POST':
                return $this->addNode($request);
            case 'PUT':
                return $this->updateNode($request);
            case 'DELETE':
                return $this->deleteNode($request);
        }

        return response()->json(['error' => 'Method not allowed'], 405);
    }

    public function links(Request $request)
    {
        if (ViewerDummyData::isViewer($request)) {
            if ($request->method() !== 'GET') {
                return response()->json(['success' => false, 'error' => 'Forbidden'], 403);
            }
            return response()->json(ViewerDummyData::mapLinks());
        }

        $this->ensureMapTables();

        switch ($request->method()) {
            case 'GET':
                return $this->getLinks();
            case 'POST':
                return $this->postLink($request);
            case 'DELETE':
                return $this->deleteLink($request);
        }

        return response()->json(['error' => 'Method not allowed'], 405);
    }

    public function devices()
    {
        if (ViewerDummyData::isViewer(request())) {
            return response()->json(ViewerDummyData::mapDevices());
        }

        $devices = DB::table('snmp_devices')
            ->select(['id', 'device_name', 'ip_address', 'last_status'])
            ->whereNotIn('id', function ($query) {
                $query->select('device_id')
                    ->from('map_nodes')
                    ->whereNotNull('device_id');
            })
            ->orderBy('device_name')
            ->get();

        return response()->json($devices);
    }

    private function getNodes()
    {
        $withInterfaces = (int) request()->query('with_interfaces', 0) === 1;
        $rows = DB::select("
            SELECT mn.*,
                   sd.device_name,
                   sd.ip_address,
                   sd.last_status,
                   sd.snmp_version
            FROM map_nodes mn
            LEFT JOIN snmp_devices sd ON mn.device_id = sd.id
            ORDER BY mn.created_at DESC
        ");

        $nodes = [];
        foreach ($rows as $row) {
            $node = (array) $row;
            $node['status'] = $node['last_status'] ?? 'unknown';
            $node['interfaces'] = [];
            $node['interfaces_loaded'] = false;

            $isOnline = in_array($node['status'], ['OK', 'Up', 'online'], true);
            if ($withInterfaces && !empty($node['device_id']) && $isOnline) {
                $ifs = DB::table('interfaces')
                    ->select([
                        'if_name',
                        'if_alias',
                        'if_description',
                        'if_type',
                        'is_sfp',
                        'last_seen',
                        'rx_power',
                        'tx_power',
                        'interface_type',
                        'oper_status',
                    ])
                    ->where('device_id', $node['device_id'])
                    ->where('is_monitored', 1)
                    ->where('is_sfp', 1)
                    ->orderBy('if_index')
                    ->limit(200)
                    ->get();

                foreach ($ifs as $ifRow) {
                    $rowArr = (array) $ifRow;
                    if (array_key_exists('rx_power', $rowArr)) {
                        $rowArr['rx_power'] = $rowArr['rx_power'] !== null ? (float) $rowArr['rx_power'] : null;
                    }
                    if (array_key_exists('tx_power', $rowArr)) {
                        $rowArr['tx_power'] = $rowArr['tx_power'] !== null ? (float) $rowArr['tx_power'] : null;
                    }
                    $node['interfaces'][] = $rowArr;
                }
                $node['interfaces_loaded'] = true;
            }

            $nodes[] = $node;
        }

        return response()->json($nodes);
    }

    private function addNode(Request $request)
    {
        if (($request->session()->get('auth.user.role')) !== 'admin') {
            return response()->json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        $data = $request->json()->all();
        if (!$data) {
            return response()->json(['success' => false, 'error' => 'Invalid JSON']);
        }

        $deviceId = !empty($data['device_id']) ? (int) $data['device_id'] : null;
        $nodeName = $data['node_name'] ?? '';
        $nodeType = $data['node_type'] ?? 'router';
        $x = (float) ($data['x_position'] ?? 0);
        $y = (float) ($data['y_position'] ?? 0);
        $icon = $data['icon_type'] ?? $nodeType;
        $locked = (int) ($data['is_locked'] ?? 0);

        $id = DB::table('map_nodes')->insertGetId([
            'device_id' => $deviceId,
            'node_name' => $nodeName,
            'node_type' => $nodeType,
            'x_position' => $x,
            'y_position' => $y,
            'icon_type' => $icon,
            'is_locked' => $locked,
        ]);

        return response()->json(['success' => true, 'node_id' => $id]);
    }

    private function updateNode(Request $request)
    {
        if (($request->session()->get('auth.user.role')) !== 'admin') {
            return response()->json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        $data = $request->json()->all();
        if (!$data) {
            return response()->json(['success' => false, 'error' => 'Invalid JSON']);
        }

        if (!empty($data['lock_all'])) {
            $lockVal = (int) ($data['is_locked'] ?? 0);
            DB::table('map_nodes')->update(['is_locked' => $lockVal]);
            return response()->json(['success' => true]);
        }

        if (empty($data['id'])) {
            return response()->json(['success' => false, 'error' => 'Invalid JSON']);
        }

        DB::table('map_nodes')
            ->where('id', (int) $data['id'])
            ->update([
                'node_name' => $data['node_name'] ?? '',
                'node_type' => $data['node_type'] ?? 'router',
                'x_position' => (float) ($data['x_position'] ?? 0),
                'y_position' => (float) ($data['y_position'] ?? 0),
                'icon_type' => $data['icon_type'] ?? 'router',
                'is_locked' => (int) ($data['is_locked'] ?? 0),
            ]);

        return response()->json(['success' => true]);
    }

    private function deleteNode(Request $request)
    {
        if (($request->session()->get('auth.user.role')) !== 'admin') {
            return response()->json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        $nodeId = (int) $request->query('id', 0);
        if ($nodeId <= 0) {
            return response()->json(['success' => false, 'error' => 'No node ID provided']);
        }

        DB::table('map_nodes')->where('id', $nodeId)->delete();
        return response()->json(['success' => true]);
    }

    private function getLinks()
    {
        // An end is "stale" when its switch no longer answers SNMP or its port row has not
        // been refreshed by the poller: the stored status/RX is then the last known value,
        // not the current one, and must not paint the line green (21 Okt 2026).
        $rows = DB::select("
            SELECT ml.*,
                   na.node_name AS node_a_name,
                   nb.node_name AS node_b_name,
                   COALESCE(ia.if_name, ia.if_alias) AS interface_a_name,
                   COALESCE(ib.if_name, ib.if_alias) AS interface_b_name,
                   ia.oper_status AS interface_a_status,
                   ib.oper_status AS interface_b_status,
                   ia.rx_power AS interface_a_rx,
                   ia.tx_power AS interface_a_tx,
                   ib.rx_power AS interface_b_rx,
                   ib.tx_power AS interface_b_tx,
                   (ia.id IS NOT NULL AND (COALESCE(sda.last_status, '') <> 'OK' OR ia.last_seen IS NULL OR ia.last_seen < NOW() - INTERVAL ? MINUTE)) AS interface_a_stale,
                   (ib.id IS NOT NULL AND (COALESCE(sdb.last_status, '') <> 'OK' OR ib.last_seen IS NULL OR ib.last_seen < NOW() - INTERVAL ? MINUTE)) AS interface_b_stale
            FROM map_links ml
            LEFT JOIN map_nodes na ON ml.node_a_id = na.id
            LEFT JOIN map_nodes nb ON ml.node_b_id = nb.id
            LEFT JOIN interfaces ia ON ml.interface_a_id = ia.id
            LEFT JOIN interfaces ib ON ml.interface_b_id = ib.id
            LEFT JOIN snmp_devices sda ON ia.device_id = sda.id
            LEFT JOIN snmp_devices sdb ON ib.device_id = sdb.id
            ORDER BY ml.created_at DESC
        ", [self::LINK_STALE_AFTER_MINUTES, self::LINK_STALE_AFTER_MINUTES]);

        $links = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $row['interface_a_stale'] = (bool) ($row['interface_a_stale'] ?? false);
            $row['interface_b_stale'] = (bool) ($row['interface_b_stale'] ?? false);
            $row['interface_a_status'] = isset($row['interface_a_status']) ? (int) $row['interface_a_status'] : null;
            $row['interface_b_status'] = isset($row['interface_b_status']) ? (int) $row['interface_b_status'] : null;
            $row['interface_a_rx'] = $row['interface_a_rx'] !== null ? (float) $row['interface_a_rx'] : null;
            $row['interface_a_tx'] = $row['interface_a_tx'] !== null ? (float) $row['interface_a_tx'] : null;
            $row['interface_b_rx'] = $row['interface_b_rx'] !== null ? (float) $row['interface_b_rx'] : null;
            $row['interface_b_tx'] = $row['interface_b_tx'] !== null ? (float) $row['interface_b_tx'] : null;
            $links[] = $row;
        }

        return response()->json($links);
    }

    private function postLink(Request $request)
    {
        if (($request->session()->get('auth.user.role')) !== 'admin') {
            return response()->json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        $data = $request->json()->all();
        if (!$data) {
            return response()->json(['success' => false, 'error' => 'Invalid JSON']);
        }

        if (!empty($data['action']) && $data['action'] === 'delete') {
            $id = (int) ($data['id'] ?? 0);
            if ($id <= 0) {
                return response()->json(['success' => false, 'error' => 'No link ID provided']);
            }
            DB::table('map_links')->where('id', $id)->delete();
            return response()->json(['success' => true]);
        }

        if (!empty($data['action']) && $data['action'] === 'update_path') {
            $id = (int) ($data['id'] ?? 0);
            if ($id <= 0) {
                return response()->json(['success' => false, 'error' => 'No link ID provided']);
            }
            $path = isset($data['path']) ? json_encode($data['path']) : null;
            DB::table('map_links')->where('id', $id)->update(['path_json' => $path]);
            return response()->json(['success' => true]);
        }

        $nodeA = (int) ($data['node_a_id'] ?? 0);
        $nodeB = (int) ($data['node_b_id'] ?? 0);
        $ifaceA = (int) ($data['interface_a_id'] ?? 0);
        $ifaceB = (int) ($data['interface_b_id'] ?? 0);
        $attenuation = $data['attenuation_db'] ?? null;
        $notes = $data['notes'] ?? null;
        $pathJson = isset($data['path']) ? json_encode($data['path']) : null;

        if ($nodeA === 0 || $nodeB === 0 || $ifaceA === 0 || $ifaceB === 0) {
            return response()->json(['success' => false, 'error' => 'Missing node or interface selection']);
        }
        if ($nodeA === $nodeB) {
            return response()->json(['success' => false, 'error' => 'Node A and Node B must be different']);
        }

        $id = DB::table('map_links')->insertGetId([
            'node_a_id' => $nodeA,
            'node_b_id' => $nodeB,
            'interface_a_id' => $ifaceA,
            'interface_b_id' => $ifaceB,
            'attenuation_db' => ($attenuation === '' || $attenuation === null) ? null : (string) $attenuation,
            'notes' => $notes,
            'path_json' => $pathJson,
        ]);

        return response()->json(['success' => true, 'id' => $id]);
    }

    private function deleteLink(Request $request)
    {
        if (($request->session()->get('auth.user.role')) !== 'admin') {
            return response()->json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        $id = (int) $request->query('id', 0);
        if ($id <= 0) {
            return response()->json(['success' => false, 'error' => 'No link ID provided']);
        }

        DB::table('map_links')->where('id', $id)->delete();
        return response()->json(['success' => true]);
    }

    private function ensureMapTables(): void
    {
        if (!Schema::hasTable('map_nodes')) {
            DB::statement("
                CREATE TABLE map_nodes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    device_id INT NULL,
                    node_name VARCHAR(100),
                    node_type VARCHAR(50),
                    x_position DOUBLE(10,6),
                    y_position DOUBLE(10,6),
                    icon_type VARCHAR(50),
                    is_locked BOOLEAN DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_device (device_id),
                    FOREIGN KEY (device_id) REFERENCES snmp_devices(id) ON DELETE SET NULL
                )
            ");
        }

        if (!Schema::hasTable('map_links')) {
            DB::statement("
                CREATE TABLE map_links (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    node_a_id INT NOT NULL,
                    node_b_id INT NOT NULL,
                    interface_a_id INT NOT NULL,
                    interface_b_id INT NOT NULL,
                    attenuation_db DECIMAL(6,2) DEFAULT NULL,
                    notes VARCHAR(255) DEFAULT NULL,
                    path_json TEXT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_nodes (node_a_id, node_b_id),
                    INDEX idx_interfaces (interface_a_id, interface_b_id),
                    FOREIGN KEY (node_a_id) REFERENCES map_nodes(id) ON DELETE CASCADE,
                    FOREIGN KEY (node_b_id) REFERENCES map_nodes(id) ON DELETE CASCADE,
                    FOREIGN KEY (interface_a_id) REFERENCES interfaces(id) ON DELETE CASCADE,
                    FOREIGN KEY (interface_b_id) REFERENCES interfaces(id) ON DELETE CASCADE
                )
            ");
        }

        if (!Schema::hasColumn('map_links', 'path_json')) {
            DB::statement("ALTER TABLE map_links ADD COLUMN path_json TEXT DEFAULT NULL AFTER notes");
        }
    }
}
