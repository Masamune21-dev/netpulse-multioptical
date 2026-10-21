// Network Map Application
let map = null;
let mapLocked = false;
let nodes = [];
let connections = [];
let selectedNode = null;
let nodeMarkers = [];
let markerById = new Map();
let clickedLatLng = null;
let mapRefreshPaused = false;
let connectionLayer = null;
let gridOverlay = null;
let connectionsVisible = true;
let gridVisible = true;
let manualLinks = [];
let pendingConnectionDeleteId = null;
let lineEditMode = false;
let connectionPolylines = new Map();
let activeConnectionId = null;
let editHandleLayer = null;


// Icon definitions
const nodeIcons = {
    router: { icon: 'fa-router', colorVar: '--primary' },
    switch: { icon: 'fa-server', colorVar: '--primary' },
    firewall: { icon: 'fa-shield-alt', colorVar: '--primary' },
    ap: { icon: 'fa-wifi', colorVar: '--primary' },
    server: { icon: 'fa-server', colorVar: '--primary' },
    client: { icon: 'fa-desktop', colorVar: '--primary' },
    cloud: { icon: 'fa-cloud', colorVar: '--primary' }
};

// Initialize Map
function initMap() {

    map = L.map('networkMap', {
        minZoom: 3,
        maxZoom: 20
    });

    const streetLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 20,
        attribution: '&copy; OpenStreetMap'
    });

    const satelliteLayer = L.tileLayer(
        'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
        {
            maxZoom: 19,
            attribution: 'Tiles &copy; Esri'
        }
    );

    const lightLayer = L.tileLayer(
        'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
        {
            maxZoom: 20,
            attribution: '&copy; CARTO'
        }
    );

    const darkLayer = L.tileLayer(
        'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
        {
            maxZoom: 20,
            attribution: '&copy; CARTO'
        }
    );

    streetLayer.addTo(map);

    const baseMaps = {
        'Street': streetLayer,
        'Satellite': satelliteLayer,
        'Light': lightLayer,
        'Dark': darkLayer
    };
    L.control.layers(baseMaps, null, { position: 'topright' }).addTo(map);

    const fullscreenControl = L.control({ position: 'topleft' });
    fullscreenControl.onAdd = function () {
        const btn = L.DomUtil.create('button', 'leaflet-control-fullscreen');
        btn.type = 'button';
        btn.title = 'Fullscreen';
        btn.innerHTML = '<i class="fas fa-expand"></i>';

        L.DomEvent.on(btn, 'click', (e) => {
            L.DomEvent.stopPropagation(e);
            L.DomEvent.preventDefault(e);
            const mapEl = document.getElementById('networkMap');
            if (!document.fullscreenElement) {
                if (mapEl && mapEl.requestFullscreen) {
                    mapEl.requestFullscreen();
                }
            } else {
                document.exitFullscreen();
            }
        });

        return btn;
    };
    fullscreenControl.addTo(map);

    document.addEventListener('fullscreenchange', () => {
        const btn = document.querySelector('.leaflet-control-fullscreen');
        if (!btn) return;
        if (document.fullscreenElement) {
            btn.innerHTML = '<i class="fas fa-compress"></i>';
        } else {
            btn.innerHTML = '<i class="fas fa-expand"></i>';
        }
    });

    map.on('click', function (e) {
        if (lineEditMode) {
            clearConnectionSelection();
            return;
        }

        clickedLatLng = e.latlng;

        if (window.tempMarker) {
            map.removeLayer(window.tempMarker);
        }

        window.tempMarker = L.marker(e.latlng).addTo(map);

        openAddNodeModal();

    });

    map.setView([-6.748973663434672, 110.97523378333311], 10);

    map.whenReady(() => {
        try {
            const savedLock = localStorage.getItem('mapLocked');
            if (savedLock === '1') {
                mapLocked = true;
            }
        } catch (e) {}
        connectionLayer = L.layerGroup().addTo(map);
        loadMapData();
        loadAvailableDevices();
    });
}

function addGridLayer() {
    const gridLayer = L.layerGroup().addTo(map);

    const gridSize = 50;
    const bounds = map.getBounds();
    const southWest = bounds.getSouthWest();
    const northEast = bounds.getNorthEast();

    for (let x = Math.floor(southWest.lng / gridSize) * gridSize; x <= northEast.lng; x += gridSize) {
        const line = L.polyline([
            [southWest.lat, x],
            [northEast.lat, x]
        ], {
            color: 'rgba(0,0,0,0.1)',
            weight: 1,
            interactive: false
        }).addTo(gridLayer);
    }

    for (let y = Math.floor(southWest.lat / gridSize) * gridSize; y <= northEast.lat; y += gridSize) {
        const line = L.polyline([
            [y, southWest.lng],
            [y, northEast.lng]
        ], {
            color: 'rgba(0,0,0,0.1)',
            weight: 1,
            interactive: false
        }).addTo(gridLayer);
    }
}

function testMarker() {
    const testIcon = L.divIcon({
        html: `
            <div class="node-marker">
                <div class="node-icon router">
                    <i class="fas fa-router"></i>
                    <div class="node-status up"></div>
                </div>
                <div class="node-label">Test Router</div>
            </div>
        `,
        className: 'custom-node-marker',
        iconSize: [60, 60],
        iconAnchor: [30, 30]
    });

    const marker = L.marker([0, 0], { icon: testIcon }).addTo(map);

    marker.bindPopup('Test marker - Map is working!').openPopup();

    console.log('Test marker added');
}

// Load all map data
async function loadMapData() {
    if (document.hidden) {
        mapRefreshPaused = true;
        return;
    }
    try {
        const response = await fetch('api/map_nodes?with_interfaces=1');
        nodes = await response.json();

        if (nodes.length) {
            const allLocked = nodes.every(n => String(n.is_locked) === '1');
            const allUnlocked = nodes.every(n => String(n.is_locked) === '0');
            mapLocked = allLocked ? true : (allUnlocked ? false : mapLocked);
            try {
                localStorage.setItem('mapLocked', mapLocked ? '1' : '0');
            } catch (e) {}
        }

        // Clear existing markers
        nodeMarkers.forEach(marker => map.removeLayer(marker));
        nodeMarkers = [];
        markerById = new Map();

        // Create new markers
        nodes.forEach(node => {
            createNodeMarker(node);
        });

        applyLockState(false);

        await loadMapLinks();
        buildConnections();
        renderConnections();

        // Update device filter
        updateDeviceFilter();

    } catch (error) {
        console.error('Error loading map data:', error);
        showNotification('Failed to load map data', 'error');
    }
}

// Rebind tooltip so the node name can either stick (selected) or only appear on hover.
function setNodeTooltipPermanent(marker, permanent) {
    if (!marker || !marker.nodeData) return;
    marker.unbindTooltip();
    // Leaflet memperlakukan string tooltip sebagai HTML — nama node wajib di-escape.
    marker.bindTooltip(escHtml(marker.nodeData.node_name), {
        permanent: permanent,
        direction: 'top',
        offset: [0, -28],
        className: 'leaflet-node-label',
        sticky: !permanent,
    });
}

// Create node marker
function createNodeMarker(node) {
    const icon = nodeIcons[node.node_type] || nodeIcons.router;

    // Create custom HTML marker
    const markerHtml = `
        <div class="node-marker" data-node-id="${escHtml(node.id)}">
            <div class="node-icon ${escHtml(node.node_type)}" 
                 style="background: var(--primary-gradient)">
                <i class="fas ${icon.icon}"></i>
                <div class="node-status ${node.status === 'OK' ? 'up' : 'down'}"></div>
            </div>
        </div>
    `;

    // Create Leaflet marker with custom HTML
    const marker = L.marker([node.y_position, node.x_position], {
        icon: L.divIcon({
            html: markerHtml,
            className: 'custom-node-marker',
            iconSize: [1, 1],
            iconAnchor: [0, 0]

        }),
        draggable: true
    }).addTo(map);

    // Store reference (must be set before binding tooltip — the helper reads nodeData.node_name)
    marker.nodeData = node;
    nodeMarkers.push(marker);
    markerById.set(node.id, marker);

    setNodeTooltipPermanent(marker, false);

    if (mapLocked || node.is_locked == 1) {
        marker.dragging.disable();
    }

    // Add click event
    marker.on('click', function (e) {
        L.DomEvent.stopPropagation(e);
        selectNode(node);
    });

    // Add drag event
    // Add drag event
    if (!node.is_locked && !mapLocked) {
        marker.on('dragend', function (e) {
            updateNodePosition(node.id, e.target.getLatLng());
        });
    }

    return marker;
}

function parsePath(raw) {
    if (!raw) return [];
    try {
        const parsed = typeof raw === 'string' ? JSON.parse(raw) : raw;
        if (!Array.isArray(parsed)) return [];
        return parsed
            .map(p => {
                if (Array.isArray(p) && p.length >= 2) {
                    return { lat: Number(p[0]), lng: Number(p[1]) };
                }
                if (p && typeof p === 'object') {
                    return { lat: Number(p.lat), lng: Number(p.lng) };
                }
                return null;
            })
            .filter(p => p && Number.isFinite(p.lat) && Number.isFinite(p.lng));
    } catch (e) {
        return [];
    }
}

function buildConnections() {
    connections = manualLinks.map(link => ({
        id: link.id,
        fromId: link.node_a_id,
        toId: link.node_b_id,
        interfaceA: link.interface_a_name,
        interfaceB: link.interface_b_name,
        statusA: link.interface_a_status,
        statusB: link.interface_b_status,
        attenuation: link.attenuation_db,
        rxA: link.interface_a_rx,
        txA: link.interface_a_tx,
        rxB: link.interface_b_rx,
        txB: link.interface_b_tx,
        staleA: link.interface_a_stale === true,
        staleB: link.interface_b_stale === true,
        path: parsePath(link.path_json)
    }));
}

// RX of one end, or null when unknown. A stale end (switch not answering SNMP, port not
// refreshed) only holds its last known value, so it must not decide the line colour.
// Number(null) is 0, so null/'' are checked first — otherwise a missing RX read as 0 dBm.
function linkEndRx(rx, stale) {
    if (stale || rx === null || rx === undefined || rx === '') return null;
    const v = Number(rx);
    return Number.isFinite(v) ? v : null;
}

function computeLinkAttenuation(connection) {
    const rxA = linkEndRx(connection.rxA, connection.staleA);
    const rxB = linkEndRx(connection.rxB, connection.staleB);
    if (rxA !== null && rxB !== null) {
        return (rxA + rxB) / 2;
    }
    if (rxA !== null) return rxA;
    if (rxB !== null) return rxB;
    return null;
}

function getConnectionLatLngs(connection) {
    const fromMarker = markerById.get(connection.fromId);
    const toMarker = markerById.get(connection.toId);
    if (!fromMarker || !toMarker) return null;
    const fromLatLng = fromMarker.getLatLng();
    const toLatLng = toMarker.getLatLng();
    const midPoints = (connection.path || []).map(p => L.latLng(p.lat, p.lng));
    return [fromLatLng, ...midPoints, toLatLng];
}

function setSelectedConnection(id) {
    activeConnectionId = id;
    connectionPolylines.forEach((line, lineId) => {
        if (lineId === id) {
            line.setStyle({ weight: 5, opacity: 0.95 });
        } else {
            line.setStyle({ weight: 3, opacity: 0.85 });
        }
    });
}

function clearConnectionSelection() {
    activeConnectionId = null;
    if (editHandleLayer) {
        editHandleLayer.clearLayers();
    }
    connectionPolylines.forEach(line => {
        line.setStyle({ weight: 3, opacity: 0.85 });
    });
}

function refreshConnectionHandles(connection) {
    if (!editHandleLayer) {
        editHandleLayer = L.layerGroup().addTo(map);
    }
    editHandleLayer.clearLayers();
    const points = connection.path || [];
    points.forEach((pt, idx) => {
        const marker = L.marker([pt.lat, pt.lng], {
            draggable: true,
            icon: L.divIcon({
                className: 'line-handle',
                iconSize: [12, 12],
                iconAnchor: [6, 6]
            })
        }).addTo(editHandleLayer);

        marker.on('drag', () => {
            const pos = marker.getLatLng();
            connection.path[idx] = { lat: pos.lat, lng: pos.lng };
            const line = connectionPolylines.get(connection.id);
            if (line) {
                const latlngs = getConnectionLatLngs(connection);
                if (latlngs) line.setLatLngs(latlngs);
            }
        });

        marker.on('dragend', () => {
            saveConnectionPath(connection);
        });

        marker.on('click', (e) => {
            const ev = e.originalEvent || {};
            if (ev.altKey) {
                connection.path.splice(idx, 1);
                refreshConnectionHandles(connection);
                const line = connectionPolylines.get(connection.id);
                if (line) {
                    const latlngs = getConnectionLatLngs(connection);
                    if (latlngs) line.setLatLngs(latlngs);
                }
                saveConnectionPath(connection);
            }
        });
    });
}

function addPointToConnection(connection, latlng) {
    if (!connection.path) connection.path = [];
    const latlngs = getConnectionLatLngs(connection);
    if (!latlngs || latlngs.length < 2) return;

    let bestIndex = 0;
    let bestDist = Infinity;
    for (let i = 0; i < latlngs.length - 1; i++) {
        const p = map.latLngToLayerPoint(latlng);
        const a = map.latLngToLayerPoint(latlngs[i]);
        const b = map.latLngToLayerPoint(latlngs[i + 1]);
        const dist = L.LineUtil.pointToSegmentDistance(p, a, b);
        if (dist < bestDist) {
            bestDist = dist;
            bestIndex = i;
        }
    }

    connection.path.splice(bestIndex, 0, { lat: latlng.lat, lng: latlng.lng });
    const line = connectionPolylines.get(connection.id);
    if (line) {
        const updated = getConnectionLatLngs(connection);
        if (updated) line.setLatLngs(updated);
    }
    refreshConnectionHandles(connection);
    saveConnectionPath(connection);
}

async function saveConnectionPath(connection) {
    if (window.roleUtils && !window.roleUtils.requireAdmin()) return;
    try {
        await fetch('api/map_links', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'update_path',
                id: connection.id,
                path: connection.path || []
            })
        });
    } catch (e) {
        console.error(e);
        showNotification('Failed to save line path', 'error');
    }
}

function renderConnections() {
    if (!connectionLayer) return;
    connectionLayer.clearLayers();
    connectionPolylines.clear();

    if (!connectionsVisible) {
        return;
    }

    connections.forEach(connection => {
        const latlngs = getConnectionLatLngs(connection);
        if (!latlngs) return;
        const fromMarker = markerById.get(connection.fromId);
        const toMarker = markerById.get(connection.toId);
        if (!fromMarker || !toMarker) return;
        if (!map.hasLayer(fromMarker) || !map.hasLayer(toMarker)) return;
        const weight = 3;
        const actualAtt = computeLinkAttenuation(connection);
        const rxA = linkEndRx(connection.rxA, connection.staleA);
        const rxB = linkEndRx(connection.rxB, connection.staleB);
        const downA = !connection.staleA && (Number(connection.statusA) === 2 || (rxA !== null && rxA <= -40));
        const downB = !connection.staleB && (Number(connection.statusB) === 2 || (rxB !== null && rxB <= -40));
        let color = 'rgba(16, 185, 129, 0.85)';

        if (connection.staleA && connection.staleB) {
            // Neither switch is reporting: the link state is unknown, not "up".
            color = 'rgba(148, 163, 184, 0.85)';
        } else if (downA || downB) {
            // A live end reports the port down (or RX -40): force red.
            color = 'rgba(239, 68, 68, 0.85)';
        } else if (Number.isFinite(actualAtt) && actualAtt <= -18 && actualAtt >= -25) {
            color = 'rgba(245, 158, 11, 0.9)'; // yellow
        } else if (Number.isFinite(actualAtt) && actualAtt < -25 && actualAtt >= -40) {
            color = 'rgba(239, 68, 68, 0.85)'; // red
        }

        const line = L.polyline(latlngs, {
            color,
            weight,
            dashArray: lineEditMode ? null : '6 6',
            className: lineEditMode ? 'connection-line editable' : 'connection-line',
            interactive: lineEditMode
        });

        line.addTo(connectionLayer);
        connectionPolylines.set(connection.id, line);

        line.on('click', (e) => {
            if (!lineEditMode) return;
            L.DomEvent.stopPropagation(e);
            const ev = e.originalEvent || {};
            setSelectedConnection(connection.id);
            refreshConnectionHandles(connection);
            if (ev.shiftKey) {
                addPointToConnection(connection, e.latlng);
            }
        });
    });

    if (!lineEditMode) {
        clearConnectionSelection();
    }
}

// Select node and show details
function selectNode(node) {
    if (selectedNode && selectedNode.id !== node.id) {
        const prevMarker = markerById.get(selectedNode.id);
        if (prevMarker) setNodeTooltipPermanent(prevMarker, false);
    }

    selectedNode = node;
    showNodeSidebar(node);
    highlightNode(node.id);
    const markerEl = document.querySelector(`.node-marker[data-node-id="${node.id}"]`);
    if (markerEl) markerEl.classList.add('show-label');
    const marker = markerById.get(node.id);
    if (marker) setNodeTooltipPermanent(marker, true);
}

// Show node details in sidebar
function showNodeSidebar(node) {
    const sidebar = document.getElementById('nodeSidebar');
    const content = document.getElementById('nodeDetailContent');

    // Create status badge
    const statusBadge = node.status === 'OK'
        ? '<span class="badge badge-success">Online</span>'
        : '<span class="badge badge-danger">Offline</span>';

    // Create interfaces table
    let interfacesHtml = '';
    if (node.interfaces && node.interfaces.length > 0) {
        interfacesHtml = `
            <div class="interfaces-section">
                <h4><i class="fas fa-plug"></i> SFP Interfaces</h4>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Interface</th>
                                <th>RX Power</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${node.interfaces.map(iface => `
                                <tr>
                                    <td>
                                        <code class="iface-name ${getPowerClass(parseFloat(iface.rx_power))}">${escHtml(iface.if_name)}</code>
                                        ${iface.if_alias || iface.if_description ? `
                                            <div style="font-size:0.75rem;color:#94a3b8;margin-top:4px;">
                                                ${escHtml(iface.if_alias || iface.if_description)}
                                            </div>
                                        ` : ''}
                                    </td>
                                    <td>
                                        ${iface.rx_power !== null && !isNaN(parseFloat(iface.rx_power)) ? `
                                            <span class="power-value ${getPowerClass(parseFloat(iface.rx_power))}">
                                                ${parseFloat(iface.rx_power).toFixed(2)} dBm
                                            </span>
                                        ` : '-'}
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    } else {
        interfacesHtml = `
            <div class="alert warning">
                <i class="fas fa-info-circle"></i>
                No interface data available. Run discovery first.
            </div>
        `;
    }

    content.innerHTML = `
        <div class="node-info">
            <div class="node-header">
                <div class="node-icon-large ${escHtml(node.node_type)}">
                    <i class="fas ${nodeIcons[node.node_type]?.icon || 'fa-server'} fa-2x"></i>
                </div>
                <div class="node-title">
                    <h4>${escHtml(node.node_name)}</h4>
                    <p class="node-subtitle">${escHtml(node.device_name || 'No device linked')}</p>
                </div>
                ${statusBadge}
            </div>
            
            <div class="node-details">
                <div class="detail-grid">
                    <div class="detail-item">
                        <label><i class="fas fa-globe"></i> IP Address</label>
                        <span>${escHtml(node.ip_address || 'N/A')}</span>
                    </div>
                    <div class="detail-item">
                        <label><i class="fas fa-exchange-alt"></i> SNMP Version</label>
                        <span>${escHtml(node.snmp_version ? 'v' + node.snmp_version : 'N/A')}</span>
                    </div>
                    <div class="detail-item">
                        <label><i class="fas fa-map-marker-alt"></i> Position</label>
                        <span>(${escHtml(node.x_position)}, ${escHtml(node.y_position)})</span>
                    </div>
                    <div class="detail-item">
                        <label><i class="fas fa-lock"></i> Status</label>
                        <span>${node.is_locked ? 'Locked' : 'Movable'}</span>
                    </div>
                </div>
                
                <div class="node-actions">
                    ${node.device_id ? `
                        <button class="btn btn-sm" onclick="testNodeSNMP(${Number(node.device_id)})">
                            <i class="fas fa-plug"></i> Test SNMP
                        </button>
                        <button class="btn btn-sm btn-outline" onclick="discoverNodeInterfaces(${Number(node.device_id)})">
                            <i class="fas fa-search"></i> Discover Interfaces
                        </button>
                    ` : ''}
                    <button class="btn btn-sm btn-primary action-edit" onclick="editNode(${Number(node.id)})">
                        <i class="fas fa-edit"></i> Edit Node
                    </button>
                    <button class="btn btn-sm btn-danger action-delete" onclick="deleteNodeQuick(${Number(node.id)})">
                        <i class="fas fa-trash-alt"></i> Delete Node
                    </button>
                </div>
                
                ${interfacesHtml}
            </div>
        </div>
    `;

    sidebar.classList.add('open');
}

// Close node sidebar
function closeNodeSidebar() {
    document.getElementById('nodeSidebar').classList.remove('open');
    if (selectedNode) {
        const prevMarker = markerById.get(selectedNode.id);
        if (prevMarker) setNodeTooltipPermanent(prevMarker, false);
    }
    selectedNode = null;
    unhighlightAllNodes();
    document.querySelectorAll('.node-marker.show-label').forEach(el => el.classList.remove('show-label'));
    nodeMarkers.forEach(m => {
        if (m.getTooltip && m.getTooltip()) {
            m.closeTooltip();
        }
    });
}

// Highlight selected node
function highlightNode(nodeId) {
    unhighlightAllNodes();
    const marker = nodeMarkers.find(m => m.nodeData.id === nodeId);
    if (marker) {
        const el = marker.getElement();
        if (el) el.classList.add('highlighted');

    }
}

// Unhighlight all nodes
function unhighlightAllNodes() {
    document.querySelectorAll('.node-marker').forEach(marker => {
        marker.classList.remove('highlighted');
    });
}

// Load available devices for dropdown
async function loadAvailableDevices() {
    try {
        const response = await fetch('api/map_devices');
        const devices = await response.json();

        const select = document.getElementById('nodeDeviceSelect');
        select.innerHTML = '<option value="">-- Select Device --</option>';

        devices.forEach(device => {
            select.innerHTML += `
                <option value="${escHtml(device.id)}">
                    ${escHtml(device.device_name)} (${escHtml(device.ip_address)})
                </option>
            `;
        });

    } catch (error) {
        console.error('Error loading devices:', error);
    }
}

// Add new node
async function addNode() {
    if (window.roleUtils && !window.roleUtils.requireAdmin()) return;
    const deviceId = document.getElementById('nodeDeviceSelect').value;
    const nodeName = document.getElementById('nodeName').value;
    const nodeType = document.getElementById('nodeType').value;

    if (!clickedLatLng) {
        showNotification('Klik map dulu untuk menentukan posisi node', 'warning');
        return;
    }

    const nodeX = clickedLatLng.lng;
    const nodeY = clickedLatLng.lat;

    const iconType = document.getElementById('nodeIcon').value;

    if (!nodeName.trim()) {
        showNotification('Please enter a node name', 'warning');
        return;
    }

    const nodeData = {
        device_id: deviceId || null,
        node_name: nodeName,
        node_type: nodeType,
        x_position: nodeX,
        y_position: nodeY,
        icon_type: iconType,
        is_locked: 0
    };

    try {
        const response = await fetch('api/map_nodes', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(nodeData)
        });

        const result = await response.json();

        if (result.success) {
            closeModal();
            loadMapData();
            map.panTo([nodeY, nodeX]);

            clickedLatLng = null;

            if (window.tempMarker) {
                map.removeLayer(window.tempMarker);
                window.tempMarker = null;
            }

            showNotification('Node added successfully', 'success');
        } else {
            showNotification('Failed to add node: ' + result.error, 'error');
        }

    } catch (error) {
        console.error('Error adding node:', error);
        showNotification('Failed to add node', 'error');
    }
}


// Edit node
function editNode(nodeId) {
    if (window.roleUtils && !window.roleUtils.requireAdmin()) return;
    const node = nodes.find(n => n.id == nodeId);
    if (!node) {
        showNotification('Node not found', 'error');
        return;
    }

    document.getElementById('editNodeId').value = node.id;
    document.getElementById('editNodeName').value = node.node_name;
    document.getElementById('editNodeType').value = node.node_type;
    document.getElementById('editNodeX').value = node.x_position;
    document.getElementById('editNodeY').value = node.y_position;
    document.getElementById('editNodeLocked').value = node.is_locked;

    document.getElementById('editNodeModal').style.display = 'flex';
    closeNodeSidebar();
}


// Update node
async function updateNode() {
    if (window.roleUtils && !window.roleUtils.requireAdmin()) return;
    const nodeId = document.getElementById('editNodeId').value;
    const nodeName = document.getElementById('editNodeName').value;
    const nodeType = document.getElementById('editNodeType').value;
    const nodeX = parseFloat(document.getElementById('editNodeX').value);
    const nodeY = parseFloat(document.getElementById('editNodeY').value);
    const isLocked = parseInt(document.getElementById('editNodeLocked').value);

    const nodeData = {
        id: nodeId,
        node_name: nodeName,
        node_type: nodeType,
        x_position: nodeX,
        y_position: nodeY,
        icon_type: nodeType,
        is_locked: isLocked
    };

    try {
        const response = await fetch('api/map_nodes', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(nodeData)
        });

        const result = await response.json();

        if (result.success) {
            closeEditModal();
            loadMapData();
            showNotification('Node updated successfully', 'success');
        } else {
            showNotification('Failed to update node: ' + result.error, 'error');
        }

    } catch (error) {
        console.error('Error updating node:', error);
        showNotification('Failed to update node', 'error');
    }
}

// Delete node
async function deleteNode() {
    if (window.roleUtils && !window.roleUtils.requireAdmin()) return;
    confirmDelete('Hapus node ini?', async () => {
        const nodeId = document.getElementById('editNodeId').value;

        try {
            const response = await fetch(`api/map_nodes?id=${nodeId}`, {
                method: 'DELETE'
            });

            const result = await response.json();

            if (result.success) {
                closeEditModal();
                loadMapData();
                showNotification('Node deleted successfully', 'success');
            } else {
                showNotification('Failed to delete node: ' + result.error, 'error');
            }

        } catch (error) {
            console.error('Error deleting node:', error);
            showNotification('Failed to delete node', 'error');
        }
    });
}

async function deleteNodeQuick(id) {
    if (window.roleUtils && !window.roleUtils.requireAdmin()) return;
    confirmDelete('Hapus node ini?', async () => {
        try {
            const response = await fetch(`api/map_nodes?id=${id}`, {
                method: 'DELETE'
            });

            const result = await response.json();

            if (result.success) {
                closeNodeSidebar();
                loadMapData();
                showNotification('Node deleted', 'success');
            } else {
                showNotification(result.error || 'Delete failed', 'error');
            }

        } catch (e) {
            console.error(e);
            showNotification('Delete failed', 'error');
        }
    });
}

// Update node position after drag
async function updateNodePosition(nodeId, latLng) {
    if (window.roleUtils && !window.roleUtils.requireAdmin()) return;

    const node = nodes.find(n => n.id == nodeId);
    if (!node) return;

    const newX = parseFloat(latLng.lng.toFixed(6));
    const newY = parseFloat(latLng.lat.toFixed(6));

    // UPDATE LOCAL CACHE
    node.x_position = newX;
    node.y_position = newY;

    await fetch('api/map_nodes', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            id: nodeId,
            node_name: node.node_name,
            node_type: node.node_type,
            x_position: newX,
            y_position: newY,
            icon_type: node.node_type,
            is_locked: node.is_locked
        })
    });

    renderConnections();
}


// Toggle map lock
function toggleLock() {
    mapLocked = !mapLocked;
    try {
        localStorage.setItem('mapLocked', mapLocked ? '1' : '0');
    } catch (e) {}
    setAllNodesLock(mapLocked);

}

function toggleLineEdit() {
    if (window.roleUtils && !window.roleUtils.requireAdmin()) return;
    lineEditMode = !lineEditMode;
    const btn = document.getElementById('lineEditBtn');
    if (btn) {
        if (lineEditMode) {
            btn.innerHTML = '<i class="fas fa-pen"></i> Editing Lines';
            btn.classList.remove('btn-outline');
        } else {
            btn.innerHTML = '<i class="fas fa-bezier-curve"></i> Edit Lines';
            btn.classList.add('btn-outline');
        }
    }
    renderConnections();
    showNotification(
        lineEditMode
            ? 'Edit mode aktif: klik line untuk pilih, shift+klik untuk tambah titik, alt+klik titik untuk hapus.'
            : 'Edit mode nonaktif',
        'info'
    );
}

async function setAllNodesLock(lockState) {
    try {
        const response = await fetch('api/map_nodes', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                lock_all: true,
                is_locked: lockState ? 1 : 0
            })
        });
        const result = await response.json();
        if (!result.success) {
            showNotification(result.error || 'Failed to update lock state', 'error');
        }
    } catch (e) {
        showNotification('Failed to update lock state', 'error');
    }

    nodes.forEach(n => {
        n.is_locked = lockState ? 1 : 0;
    });
    nodeMarkers.forEach(marker => {
        if (marker.nodeData) {
            marker.nodeData.is_locked = lockState ? 1 : 0;
        }
    });

    applyLockState(true);
}

function applyLockState(showToast = false) {
    const lockBtn = document.getElementById('lockBtn');
    if (lockBtn) {
        if (mapLocked) {
            lockBtn.innerHTML = '<i class="fas fa-lock"></i> Locked';
            lockBtn.classList.remove('btn-outline');
        } else {
            lockBtn.innerHTML = '<i class="fas fa-lock-open"></i> Unlocked';
            lockBtn.classList.add('btn-outline');
        }
    }

    // Update draggable state of all markers
    nodeMarkers.forEach(marker => {
        if (mapLocked || marker.nodeData.is_locked == 1) {
            marker.dragging.disable();
        } else {
            marker.dragging.enable();
        }
    });

    if (showToast) {
        showNotification(
            mapLocked ? 'Map locked - nodes cannot be moved' : 'Map unlocked - nodes can be moved',
            'info'
        );
    }
}

// Refresh map data
function refreshMap() {
    loadMapData();
    showNotification('Map refreshed', 'success');
}

// Filter nodes
function filterNodes() {
    const statusFilter = document.getElementById('statusFilter').value;
    const deviceFilter = document.getElementById('deviceFilter').value;

    nodeMarkers.forEach(marker => {
        let show = true;

        // Status filter
        if (statusFilter !== 'all') {
            if (statusFilter === 'up' && marker.nodeData.status !== 'OK') {
                show = false;
            } else if (statusFilter === 'down' && marker.nodeData.status === 'OK') {
                show = false;
            }
        }

        // Device filter
        if (deviceFilter !== 'all' && marker.nodeData.device_id != deviceFilter) {
            show = false;
        }

        // Show/hide marker
        if (show) {
            map.addLayer(marker);
        } else {
            map.removeLayer(marker);
        }
    });

    renderConnections();
}

async function loadMapLinks() {
    try {
        const response = await fetch('api/map_links');
        manualLinks = await response.json();
        updateConnectionList();
    } catch (error) {
        console.error('Error loading map links:', error);
    }
}

function updateConnectionList() {
    const list = document.getElementById('linkList');
    if (!list) return;
    if (!manualLinks.length) {
        list.innerHTML = '<div class="connection-item"><small>No connections yet.</small></div>';
        return;
    }
    list.innerHTML = manualLinks.map(link => `
        <div class="connection-item">
            <div>
                <div><strong>${escHtml(link.node_a_name)}</strong> (${escHtml(link.interface_a_name)})</div>
                <small>↔</small>
                <div><strong>${escHtml(link.node_b_name)}</strong> (${escHtml(link.interface_b_name)})</div>
                ${link.attenuation_db !== null ? `<small>Att: ${parseFloat(link.attenuation_db).toFixed(2)} dB</small>` : ''}
            </div>
            <div class="connection-actions">
                <button class="btn btn-sm btn-outline" type="button" data-reset-connection="${escHtml(link.id)}">
                    <i class="fas fa-undo"></i>
                </button>
                <button class="btn btn-sm btn-danger" type="button" data-delete-connection="${escHtml(link.id)}">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
    `).join('');
}

function populateNodeSelect(selectId) {
    const select = document.getElementById(selectId);
    if (!select) return;
    select.innerHTML = '<option value="">-- Select Node --</option>';
    nodes.forEach(node => {
        select.innerHTML += `<option value="${escHtml(node.id)}">${escHtml(node.node_name)}</option>`;
    });
}

async function populateInterfaceSelect(nodeId, selectId) {
    const select = document.getElementById(selectId);
    if (!select) return;
    select.innerHTML = '<option value="">-- Select Interface --</option>';
    const node = nodes.find(n => n.id == nodeId);
    if (!node || !node.device_id) return;

    try {
        const response = await fetch(`api/interfaces?device_id=${node.device_id}`);
        const ifaces = await response.json();
        ifaces.forEach(iface => {
            const name = iface.if_name || `if-${iface.if_index}`;
            const alias = iface.if_alias || '';
            const label = alias ? `${name} (${alias})` : name;
            const rx = iface.rx_power !== null && iface.rx_power !== undefined ? iface.rx_power : '';
            const tx = iface.tx_power !== null && iface.tx_power !== undefined ? iface.tx_power : '';
            select.innerHTML += `<option value="${escHtml(iface.id)}" data-rx="${escHtml(rx)}" data-tx="${escHtml(tx)}">${escHtml(label)}</option>`;
        });
    } catch (e) {
        console.error('Error loading interfaces:', e);
    }
}

function openAddLinkModal() {
    if (window.roleUtils && !window.roleUtils.requireAdmin()) return;
    document.getElementById('addLinkModal').style.display = 'flex';
    populateNodeSelect('linkNodeA');
    populateNodeSelect('linkNodeB');
    updateConnectionList();
}

function computeAttenuation() {
    const selectA = document.getElementById('linkInterfaceA');
    const selectB = document.getElementById('linkInterfaceB');
    const attenuationInput = document.getElementById('linkAttenuation');
    if (!selectA || !selectB || !attenuationInput) return;

    const optionA = selectA.options[selectA.selectedIndex];
    const optionB = selectB.options[selectB.selectedIndex];
    if (!optionA || !optionB) return;

    const txA = parseFloat(optionA.getAttribute('data-tx'));
    const rxA = parseFloat(optionA.getAttribute('data-rx'));
    const txB = parseFloat(optionB.getAttribute('data-tx'));
    const rxB = parseFloat(optionB.getAttribute('data-rx'));

    const values = [];
    if (!Number.isNaN(txA) && !Number.isNaN(rxB)) {
        values.push(txA - rxB);
    }
    if (!Number.isNaN(txB) && !Number.isNaN(rxA)) {
        values.push(txB - rxA);
    }

    if (values.length) {
        const avg = values.reduce((a, b) => a + b, 0) / values.length;
        attenuationInput.value = avg.toFixed(2);
        return;
    }

    // Fallback: show average RX (negative dBm) if TX not available
    if (!Number.isNaN(rxA) && !Number.isNaN(rxB)) {
        const avgRx = (rxA + rxB) / 2;
        attenuationInput.value = avgRx.toFixed(2);
    }
}

function closeLinkModal() {
    document.getElementById('addLinkModal').style.display = 'none';
}

async function addConnection() {
    if (window.roleUtils && !window.roleUtils.requireAdmin()) return;
    const nodeA = document.getElementById('linkNodeA').value;
    const nodeB = document.getElementById('linkNodeB').value;
    const ifaceA = document.getElementById('linkInterfaceA').value;
    const ifaceB = document.getElementById('linkInterfaceB').value;
    const attenuationRaw = document.getElementById('linkAttenuation').value;
    const notes = document.getElementById('linkNotes').value;

    if (!nodeA || !nodeB || !ifaceA || !ifaceB) {
        showNotification('Please select node and interface for both sides', 'warning');
        return;
    }

    if (String(nodeA) === String(nodeB)) {
        showNotification('Node A and Node B must be different', 'warning');
        return;
    }

    let attenuation = null;
    if (attenuationRaw !== '') {
        const normalized = attenuationRaw.replace(',', '.');
        const parsed = parseFloat(normalized);
        if (Number.isNaN(parsed)) {
            showNotification('Attenuation value is not valid', 'warning');
            return;
        }
        attenuation = parsed;
    }

    try {
        const response = await fetch('api/map_links', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                node_a_id: nodeA,
                node_b_id: nodeB,
                interface_a_id: ifaceA,
                interface_b_id: ifaceB,
                attenuation_db: attenuation,
                notes
            })
        });

        const result = await response.json();
        if (result.success) {
            showNotification('Connection saved', 'success');
            await loadMapLinks();
            buildConnections();
            renderConnections();
        } else {
            showNotification(result.error || 'Failed to save connection', 'error');
        }
    } catch (e) {
        console.error(e);
        showNotification('Failed to save connection', 'error');
    }
}

async function deleteConnection(id) {
    if (window.roleUtils && !window.roleUtils.requireAdmin()) return;
    if (!id) {
        showNotification('Invalid connection id', 'warning');
        return;
    }
    pendingConnectionDeleteId = id;
    openConnectionDeleteModal();
}

async function resetConnectionPath(id) {
    if (window.roleUtils && !window.roleUtils.requireAdmin()) return;
    if (!id) return;
    const connection = connections.find(c => String(c.id) === String(id));
    if (connection) {
        connection.path = [];
    }
    try {
        await fetch('api/map_links', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'update_path',
                id,
                path: []
            })
        });
        renderConnections();
        showNotification('Line reset to default', 'success');
    } catch (e) {
        console.error(e);
        showNotification('Failed to reset line', 'error');
    }
}

function openConnectionDeleteModal() {
    const modal = document.getElementById('confirmDeleteModal');
    const msg = document.getElementById('confirmDeleteMessage');
    if (!modal) {
        if (confirm('Hapus connection ini?')) {
            runConnectionDelete();
        }
        return;
    }
    if (msg) msg.textContent = 'Hapus connection ini?';
    modal.style.display = 'flex';
}

async function runConnectionDelete() {
    const id = pendingConnectionDeleteId;
    if (!id) return;
    pendingConnectionDeleteId = null;
    try {
        showNotification('Deleting connection...', 'info');
        const response = await fetch('api/map_links', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', id })
        });
        const result = await response.json();
        if (result.success) {
            showNotification('Connection deleted', 'success');
            await loadMapLinks();
            buildConnections();
            renderConnections();
        } else {
            showNotification(result.error || 'Delete failed', 'error');
        }
    } catch (e) {
        console.error(e);
        showNotification('Delete failed', 'error');
    }
}

// Update device filter dropdown
function updateDeviceFilter() {
    const select = document.getElementById('deviceFilter');
    select.innerHTML = '<option value="all">All Devices</option>';

    nodes.forEach(node => {
        if (node.device_name) {
            select.innerHTML += `
                <option value="${escHtml(node.device_id)}">
                    ${escHtml(node.device_name)}
                </option>
            `;
        }
    });
}

// Test SNMP for node
async function testNodeSNMP(deviceId) {
    try {
        const response = await fetch(`api/devices?test=${deviceId}`);
        const result = await response.json();

        if (result.status === 'OK') {
            showNotification('SNMP test successful', 'success');
        } else {
            showNotification('SNMP test failed: ' + result.error, 'error');
        }

        // Refresh to update status
        setTimeout(loadMapData, 1000);

    } catch (error) {
        console.error('Error testing SNMP:', error);
        showNotification('SNMP test failed', 'error');
    }
}

// Discover interfaces for node
async function discoverNodeInterfaces(deviceId) {
    try {
        const response = await fetch(`api/discover_interfaces?device_id=${deviceId}`, { method: 'POST' });
        const result = await response.json();

        if (result.success) {
            showNotification(`Discovered ${result.sfp_count} SFP interfaces`, 'success');
            // Refresh node details
            if (selectedNode) {
                setTimeout(() => selectNode(selectedNode), 1000);
            }
        } else {
            showNotification('Discovery failed: ' + result.error, 'error');
        }

    } catch (error) {
        console.error('Error discovering interfaces:', error);
        showNotification('Discovery failed', 'error');
    }
}

// Helper: Get power class for styling
function getPowerClass(power) {
    if (!Number.isFinite(power)) return 'power-good';
    if (power <= -40) return 'power-critical';
    if (power >= -25 && power <= -18) return 'power-warning';
    return 'power-good';
}

// Use global toast notifications when available
function showNotification(message, type = 'info') {
    if (window.mikrotikMonitor && typeof window.mikrotikMonitor.showNotification === 'function') {
        window.mikrotikMonitor.showNotification(message, type);
        return;
    }
    if (typeof window.showNotification === 'function') {
        window.showNotification(message, type);
    }
}

// Modal functions
function openAddNodeModal() {
    if (window.roleUtils && !window.roleUtils.requireAdmin()) return;
    document.getElementById('addNodeModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('addNodeModal').style.display = 'none';
    document.getElementById('editNodeModal').style.display = 'none';
}

function closeEditModal() {
    document.getElementById('editNodeModal').style.display = 'none';
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('mapControlToggle').addEventListener('click', () => {
        document.querySelector('.map-controls').classList.toggle('open');
    });

    gridOverlay = document.getElementById('gridOverlay');
    const gridToggle = document.getElementById('showGrid');
    if (gridToggle && gridOverlay) {
        gridVisible = gridToggle.checked;
        gridOverlay.style.display = gridVisible ? 'block' : 'none';
        gridToggle.addEventListener('change', () => {
            gridVisible = gridToggle.checked;
            gridOverlay.style.display = gridVisible ? 'block' : 'none';
        });
    }

    const connectionsToggle = document.getElementById('showConnections');
    if (connectionsToggle) {
        connectionsVisible = connectionsToggle.checked;
        connectionsToggle.addEventListener('change', () => {
            connectionsVisible = connectionsToggle.checked;
            if (connectionLayer) {
                if (connectionsVisible) {
                    if (!map.hasLayer(connectionLayer)) {
                        connectionLayer.addTo(map);
                    }
                    renderConnections();
                } else {
                    map.removeLayer(connectionLayer);
                }
            }
        });
    }

    const nodeASelect = document.getElementById('linkNodeA');
    const nodeBSelect = document.getElementById('linkNodeB');
    if (nodeASelect) {
        nodeASelect.addEventListener('change', (e) => {
            populateInterfaceSelect(e.target.value, 'linkInterfaceA');
        });
    }
    if (nodeBSelect) {
        nodeBSelect.addEventListener('change', (e) => {
            populateInterfaceSelect(e.target.value, 'linkInterfaceB');
        });
    }
    const ifaceASelect = document.getElementById('linkInterfaceA');
    const ifaceBSelect = document.getElementById('linkInterfaceB');
    if (ifaceASelect) {
        ifaceASelect.addEventListener('change', () => {
            computeAttenuation();
        });
    }
    if (ifaceBSelect) {
        ifaceBSelect.addEventListener('change', () => {
            computeAttenuation();
        });
    }

    const linkList = document.getElementById('linkList');
    if (linkList) {
        linkList.addEventListener('click', (e) => {
            const resetBtn = e.target.closest('[data-reset-connection]');
            if (resetBtn) {
                e.preventDefault();
                resetConnectionPath(resetBtn.getAttribute('data-reset-connection'));
                return;
            }
            const btn = e.target.closest('[data-delete-connection]');
            if (!btn) return;
            e.preventDefault();
            deleteConnection(btn.getAttribute('data-delete-connection'));
        });
    }

    const confirmYes = document.getElementById('confirmDeleteYes');
    if (confirmYes) {
        confirmYes.addEventListener('click', () => {
            if (pendingConnectionDeleteId) {
                if (typeof closeDeleteModal === 'function') {
                    closeDeleteModal();
                } else {
                    const modal = document.getElementById('confirmDeleteModal');
                    if (modal) modal.style.display = 'none';
                }
                runConnectionDelete();
            }
        });
    }

    const lockBtn = document.getElementById('lockBtn');
    if (lockBtn) {
        lockBtn.addEventListener('click', (e) => {
            e.preventDefault();
            toggleLock();
        });
    }

    const lineEditBtn = document.getElementById('lineEditBtn');
    if (lineEditBtn) {
        lineEditBtn.addEventListener('click', (e) => {
            e.preventDefault();
            toggleLineEdit();
        });
    }

    // Auto refresh (prefer global coordinator if available)
    if (window.netpulseRefresh && typeof window.netpulseRefresh.register === 'function') {
        window.netpulseRefresh.register('map', () => {
            if (!map) return;
            if (document.hidden) return;
            if (!location.pathname.startsWith('/map')) return;
            loadMapData();
        }, { minIntervalMs: 20000 });
    } else {
        // Fallback: refresh map data every 60s
        setInterval(() => {
            if (document.hidden) return;
            loadMapData();
        }, 60000);
    }

    initMap();
});


// Resume refresh when tab becomes active
document.addEventListener('visibilitychange', () => {
    if (!document.hidden && mapRefreshPaused) {
        mapRefreshPaused = false;
        if (map) {
            loadMapData();
        }
    }
});
