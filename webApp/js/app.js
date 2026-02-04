// ====================================================================
// HYDRAHOME CONTROL CENTER - MAIN APPLICATION
// Temperature in Celsius
// ====================================================================

// ====================================================================
// CONFIGURATION
// ====================================================================
const API_BASE_URL = 'http://192.168.8.75/smarthome/api/api_dispatcher.php';
const UPDATE_INTERVAL = 5000;
let updateTimer = null;
let chartInstance = null;

const ROOM_NAMES = {
  'kitchen': '🍳 Kitchen',
  'living_room': '🛋️ Living Room',
  'bedroom': '🛏️ Bedroom'
};

const ROOM_EMOJIS = {
  'kitchen': '🍳',
  'living_room': '🛋️',
  'bedroom': '🛏️'
};

// ====================================================================
// KEYPAD AUTHENTICATION - FIXED VERSION
// ====================================================================

let authOverlay = null;
let currentPin = '';
let isSystemLocked = true;
let isAuthenticating = false;
let lastEventId = 0; // Track last processed event


// Check system status and keypad events
async function checkAuthentication() {
    if (isAuthenticating) return; // Prevent overlapping checks
    
    try {
        isAuthenticating = true;
        
        // Check if system is ON/OFF
        const statusResponse = await fetch(`${API_BASE_URL}?action=get_system_status`);
        const statusResult = await statusResponse.json();
        
        if (statusResult.success) {
            const systemOn = statusResult.system_on;
            
            console.log('🔐 System status:', systemOn ? 'ON (Unlocked)' : 'OFF (Locked)');
            
            // Handle state changes
            if (systemOn && isSystemLocked) {
                // System just unlocked
                console.log('✅ System unlocked - showing dashboard');
                unlockDashboard();
                isSystemLocked = false;
            } else if (!systemOn && !isSystemLocked) {
                // System just locked
                console.log('🔒 System locked - showing auth screen');
                lockDashboard();
                isSystemLocked = true;
            }
        }
        
        // Only get keypad events if system is locked
        if (isSystemLocked) {
            const eventsResponse = await fetch(`${API_BASE_URL}?action=get_keypad_events&since=5 SECOND`);
            const eventsResult = await eventsResponse.json();
            
            if (eventsResult.success && eventsResult.events.length > 0) {
                // Process only new events
                const newEvents = eventsResult.events.filter(event => {
                    const eventId = new Date(event.timestamp).getTime();
                    return eventId > lastEventId;
                });
                
                if (newEvents.length > 0) {
                    // Update last event ID
                    lastEventId = new Date(newEvents[newEvents.length - 1].timestamp).getTime();
                    processKeypadEvents(newEvents);
                }
            }
        }
        
    } catch (error) {
        console.error('❌ Auth check error:', error);
    } finally {
        isAuthenticating = false;
    }
}

function processKeypadEvents(events) {
    console.log('🔑 Processing', events.length, 'keypad events');
    
    events.forEach(event => {
        console.log('  Event:', event.action, 'Key:', event.key, 'Success:', event.success);
        
        switch(event.action) {
            case 'key_press':
                if (event.key !== '#' && event.key !== '*') {
                    addPinDigit();
                }
                break;
                
            case 'clear':
                clearPin();
                break;
                
            case 'unlock_success':
                console.log('✅ Unlock success - preparing to show dashboard');
                showSuccess();
                // Don't unlock yet - wait for system status to change
                break;
                
            case 'unlock_failed':
                console.log('❌ Unlock failed');
                showError();
                setTimeout(() => clearPin(), 1000);
                break;
                
            case 'lock_success':
                console.log('🔒 Lock success');
                break;
        }
    });
}

function addPinDigit() {
    if (currentPin.length < 4) {
        currentPin += '*';
        updatePinDisplay();
    }
}



function clearPin() {
    currentPin = '';
    updatePinDisplay();
    
    // Clear all dots
    for (let i = 1; i <= 4; i++) {
        const dot = document.getElementById(`dot${i}`);
        if (dot) {
            dot.classList.remove('filled', 'error', 'success');
        }
    }
    
    // Clear feedback
    const feedback = document.getElementById('pin-feedback');
    if (feedback) {
        feedback.textContent = '';
        feedback.className = 'pin-feedback';
    }
}

function updatePinDisplay() {
    for (let i = 1; i <= 4; i++) {
        const dot = document.getElementById(`dot${i}`);
        if (dot) {
            if (i <= currentPin.length) {
                dot.classList.add('filled');
                dot.classList.remove('error', 'success');
            } else {
                dot.classList.remove('filled', 'error', 'success');
            }
        }
    }
}

function showError() {
    const modal = document.querySelector('.auth-modal');
    const feedback = document.getElementById('pin-feedback');
    
    if (modal) {
        modal.classList.add('shake');
        setTimeout(() => modal.classList.remove('shake'), 500);
    }
    
    if (feedback) {
        feedback.textContent = '❌ Incorrect PIN';
        feedback.className = 'pin-feedback error';
    }
    
    // Mark all dots as error
    for (let i = 1; i <= 4; i++) {
        const dot = document.getElementById(`dot${i}`);
        if (dot) {
            dot.classList.remove('filled');
            dot.classList.add('error');
        }
    }
}

function showSuccess() {
    const modal = document.querySelector('.auth-modal');
    const feedback = document.getElementById('pin-feedback');
    const lockIcon = document.querySelector('.lock-icon');
    
    if (modal) modal.classList.add('success');
    if (lockIcon) lockIcon.textContent = '🔓';
    if (feedback) {
        feedback.textContent = '✅ Access Granted';
        feedback.className = 'pin-feedback success';
    }
    
    // Mark all dots as success
    for (let i = 1; i <= 4; i++) {
        const dot = document.getElementById(`dot${i}`);
        if (dot) {
            dot.classList.remove('filled', 'error');
            dot.classList.add('success');
        }
    }
}

function unlockDashboard() {
    console.log('🔓 Unlocking dashboard...');
    
    authOverlay = document.getElementById('auth-overlay');
    if (authOverlay) {
        authOverlay.classList.add('unlocked');
        
        setTimeout(() => {
            authOverlay.style.display = 'none';
        }, 500);
    }
    
    isSystemLocked = false;
    clearPin();
    
    // Start dashboard updates
    if (!updateTimer) {
        console.log('🔄 Starting dashboard updates');
        fetchLatestData();
        fetchAlerts();
        startLiveUpdates();
    }
}

function lockDashboard() {
  clearPin();
    console.log('🔒 Locking dashboard...');
    
    authOverlay = document.getElementById('auth-overlay');
    if (authOverlay) {
        authOverlay.style.display = 'flex';
        authOverlay.classList.remove('unlocked');
        
        // Reset UI
        const lockIcon = document.querySelector('.lock-icon');
        if (lockIcon) lockIcon.textContent = '🔒';
        
        const feedback = document.getElementById('pin-feedback');
        if (feedback) {
            feedback.textContent = '';
            feedback.className = 'pin-feedback';
        }
    }
    
    clearPin();
    isSystemLocked = true;
    
    // Stop dashboard updates
    if (updateTimer) {
        clearInterval(updateTimer);
        updateTimer = null;
        console.log('⏸️ Dashboard updates stopped');
    }
}

// ====================================================================
// REMOTE CONTROL FUNCTIONS
// ====================================================================

let remoteUpdateInterval = null;

function openRemote() {
  console.log('🎮 Opening remote control');
  
  const overlay = document.getElementById('remote-overlay');
  if (overlay) {
    overlay.style.display = 'flex';
    
    // Load current status
    updateRemoteStatus();
    
    // Start updating every 2 seconds
    if (remoteUpdateInterval) clearInterval(remoteUpdateInterval);
    remoteUpdateInterval = setInterval(updateRemoteStatus, 2000);
  }
}

function closeRemote() {
  console.log('🎮 Closing remote control');
  
  const overlay = document.getElementById('remote-overlay');
  if (overlay) {
    overlay.style.display = 'none';
  }
  
  // Stop updating
  if (remoteUpdateInterval) {
    clearInterval(remoteUpdateInterval);
    remoteUpdateInterval = null;
  }
}

async function updateRemoteStatus() {
  try {
    const response = await fetch(`${API_BASE_URL}?action=get_latest_data`);
    const result = await response.json();
    
    if (result.success && result.data) {
      result.data.forEach(room => {
        // Update status badge
        const statusElement = document.getElementById(`${room.room}-status`);
        if (statusElement) {
          if (room.light_status || room.fan_status) {
            statusElement.textContent = 'Active';
            statusElement.classList.add('active');
          } else {
            statusElement.textContent = 'Idle';
            statusElement.classList.remove('active');
          }
        }
        
        // Update temperature
        const tempElement = document.getElementById(`${room.room}-temp`);
        if (tempElement) {
          tempElement.textContent = room.temperature.toFixed(1);
        }
        
        // Update humidity
        const humElement = document.getElementById(`${room.room}-humidity`);
        if (humElement) {
          humElement.textContent = Math.round(room.humidity);
        }
        
        // Update brightness slider and value
        const brightnessSlider = document.getElementById(`${room.room}-brightness`);
        const brightnessValue = document.getElementById(`${room.room}-brightness-value`);
        if (brightnessSlider && brightnessValue) {
          if (room.light_status && room.brightness > 0) {
            brightnessSlider.value = room.brightness;
            const percent = Math.round((room.brightness / 255) * 100);
            brightnessValue.textContent = percent + '%';
          }
        }
      });
    }
  } catch (error) {
    console.error('❌ Error updating remote status:', error);
  }
}

async function sendCommand(room, deviceType, action) {
  console.log(`📤 Sending command: ${room} ${deviceType} ${action}`);
  
  try {
    // Get brightness value if it's a light
    let brightness = 255;
    if (deviceType === 'light') {
      const slider = document.getElementById(`${room}-brightness`);
      if (slider) {
        brightness = parseInt(slider.value);
      }
    }
    
    const commandData = {
      room: room,
      device_type: deviceType,
      action: action,
      value: brightness,
      mode: 'manual'
    };
    
    console.log('📦 Command data:', commandData);
    console.log('🌐 Sending to:', `${API_BASE_URL}?action=send_command`);
    
    const response = await fetch(`${API_BASE_URL}?action=send_command`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(commandData)
    });
    
    console.log('📨 Response status:', response.status);
    
    const responseText = await response.text();
    console.log('📄 Raw response:', responseText);
    
    let result;
    try {
      result = JSON.parse(responseText);
    } catch (e) {
      console.error('❌ JSON Parse Error:', e);
      alert('Error: Server returned invalid JSON\n\n' + responseText);
      return;
    }
    
    console.log('📊 Parsed result:', result);
    
    if (result.success) {
      console.log('✅ Command sent successfully');
      showCommandFeedback(room, deviceType, action);
      setTimeout(updateRemoteStatus, 500);
    } else {
      console.error('❌ Command failed:', result);
      alert('Command failed:\n' + (result.error || 'Unknown error'));
    }
    
  } catch (error) {
    console.error('❌ Error sending command:', error);
    alert('Error sending command:\n' + error.message);
  }
}

// Current brightness update tracking
let brightnessTimeouts = {};

function updateBrightness(room, value) {
  const brightnessValue = document.getElementById(`${room}-brightness-value`);
  if (brightnessValue) {
    const percent = Math.round((value / 255) * 100);
    brightnessValue.textContent = percent + '%';
  }
  
  // Clear existing timeout for this room
  if (brightnessTimeouts[room]) {
    clearTimeout(brightnessTimeouts[room]);
  }
  
  // Send command after user stops dragging (500ms delay)
  brightnessTimeouts[room] = setTimeout(() => {
    console.log(`💡 Sending brightness update for ${room}: ${value}`);
    sendBrightnessCommand(room, value);
  }, 500);
}

async function sendBrightnessCommand(room, brightness) {
  try {
    const response = await fetch(`${API_BASE_URL}?action=send_command`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        room: room,
        device_type: 'light',
        action: 'on',
        value: parseInt(brightness),
        mode: 'manual'
      })
    });
    
    const result = await response.json();
    
    if (result.success) {
      console.log(`✅ Brightness updated for ${room}: ${brightness}`);
    } else {
      console.error('❌ Brightness update failed:', result);
    }
    
  } catch (error) {
    console.error('❌ Error updating brightness:', error);
  }
}

async function setAllAutoMode() {
  console.log('🔄 Setting all rooms to AUTO mode');
  
  const confirmation = confirm('Return all devices to AUTO mode?');
  if (!confirmation) return;
  
  const rooms = ['kitchen', 'living_room', 'bedroom'];
  const devices = ['light', 'fan'];
  
  try {
    let successCount = 0;
    
    for (const room of rooms) {
      for (const device of devices) {
        const response = await fetch(`${API_BASE_URL}?action=send_command`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            room: room,
            device_type: device,
            action: 'off',
            mode: 'auto'
          })
        });
        
        const result = await response.json();
        if (result.success) successCount++;
      }
    }
    
    console.log(`✅ ${successCount} commands sent`);
    showSuccessNotification('All devices returned to AUTO mode');
    
    // Update remote status
    setTimeout(updateRemoteStatus, 1000);
    
  } catch (error) {
    console.error('❌ Error setting auto mode:', error);
    showErrorNotification('Error setting auto mode');
  }
}

function showCommandFeedback(room, device, action) {
  const emoji = device === 'light' ? '💡' : '🌀';
  const actionText = action.toUpperCase();
  const roomName = ROOM_NAMES[room] || room;
  
  console.log(`${emoji} ${roomName} ${device} → ${actionText}`);
  
  // Optional: Add visual feedback to the button
  // You can enhance this with animations if desired
}

function showSuccessNotification(message) {
  // Simple alert for now - you can enhance with custom toast notifications
  alert('✅ ' + message);
}

function showErrorNotification(message) {
  // Simple alert for now - you can enhance with custom toast notifications
  alert('❌ ' + message);
}

// Close remote with Escape key
document.addEventListener('keydown', function(event) {
  if (event.key === 'Escape') {
    const overlay = document.getElementById('remote-overlay');
    if (overlay && overlay.style.display === 'flex') {
      closeRemote();
    }
  }
});

// Close remote when clicking outside the card
document.addEventListener('click', function(event) {
  const overlay = document.getElementById('remote-overlay');
  const remoteCard = document.querySelector('.remote-card');
  
  if (overlay && overlay.style.display === 'flex') {
    if (event.target === overlay && !remoteCard.contains(event.target)) {
      closeRemote();
    }
  }
});



// ====================================================================
// UPDATE INITIALIZATION
// ====================================================================

document.addEventListener('DOMContentLoaded', function() {
  console.log('✅ DOM loaded, initializing dashboard...');
  
  authOverlay = document.getElementById('auth-overlay');
  
  initNavbarScrollAnimation();
  initChartVisualization();
  initCardInteractions();
  
  // Initial authentication check
  console.log('🔐 Checking initial authentication state...');
  checkAuthentication();
  
  // Poll for authentication changes every 1 second (slower to reduce glitching)
  setInterval(checkAuthentication, 1000);
  
  console.log('✅ Dashboard initialized');
});

// ====================================================================
// INITIALIZATION
// ====================================================================
document.addEventListener('DOMContentLoaded', function() {
  console.log('Dashboard initializing...');
  
  initNavbarScrollAnimation();
  initChartVisualization();
  initCardInteractions();
  initNotificationSystem();
  
  fetchLatestData();
  startLiveUpdates();
  
  console.log('Dashboard initialized successfully');
});

// ====================================================================
// LIVE DATA UPDATES
// ====================================================================

async function fetchLatestData() {
  try {
    const response = await fetch(`${API_BASE_URL}?action=get_latest_data`);
    const result = await response.json();
    
    if (result.success && result.data) {
      updateDashboard(result.data);
      console.log('Data updated:', result.data);
    }
  } catch (error) {
    console.error('Error fetching data:', error);
    showError('Failed to fetch sensor data');
  }
}

async function fetchAlerts() {
  console.log('🔔 Fetching alerts...');
  
  try {
    const url = `${API_BASE_URL}?action=get_alerts&limit=10`;  // Increased to 10
    const response = await fetch(url);
    const result = await response.json();
    
    if (result.success && result.alerts) {
      console.log('✅ Alerts received:', result.alerts.length);
      updateNotifications(result.alerts);
    }
  } catch (error) {
    console.error('❌ Error fetching alerts:', error);
  }
}

async function fetchChartData(room = 'living_room') {
  try {
    const response = await fetch(`${API_BASE_URL}?action=get_chart_data&room=${room}&hours=24`);
    const result = await response.json();
    
    if (result.success && result.data) {
      updateChart(result.data);
    }
  } catch (error) {
    console.error('Error fetching chart data:', error);
  }
}

function startLiveUpdates() {
  if (updateTimer) {
    clearInterval(updateTimer);
  }
  
  updateTimer = setInterval(() => {
    fetchLatestData();
    fetchAlerts();
  }, UPDATE_INTERVAL);
  
  setInterval(() => {
    fetchChartData();
  }, 30000);
}

// ====================================================================
// UPDATE DASHBOARD ELEMENTS
// ====================================================================

function updateDashboard(data) {
  console.log('🎨 Updating dashboard with data:', data);
  
  if (!data || data.length === 0) {
    console.warn('⚠️ No data to display');
    return;
  }
  
  let activeRoom = null;
  const roomsWithMotion = data.filter(room => room.motion_detected);
  
  if (roomsWithMotion.length > 0) {
    activeRoom = roomsWithMotion.reduce((latest, current) => {
      const latestTime = new Date(latest.timestamp);
      const currentTime = new Date(current.timestamp);
      return currentTime > latestTime ? current : latest;
    });
  } else {
    activeRoom = data.reduce((latest, current) => {
      const latestTime = new Date(latest.timestamp);
      const currentTime = new Date(current.timestamp);
      return currentTime > latestTime ? current : latest;
    });
  }
  
  if (activeRoom) {
    updateActiveRoomCard(activeRoom);
  }
  
  updateRoomCards(data);
  updateRoomStats(data);
}

function updateActiveRoomCard(room) {
  console.log('🎨 Updating active room card:', room);
  
  // UPDATE ROOM NAME
  const roomNameElement = document.getElementById('active-room-name');
  if (roomNameElement) {
    const displayName = ROOM_NAMES[room.room] || room.room;
    roomNameElement.textContent = displayName;
  }
  
  // TEMPERATURE - CELSIUS
  const displayTemp = Math.round(room.temperature);
  const tempUnit = 'Celsius';
  
  const tempElement = document.getElementById('active-temp');
  if (tempElement) {
    tempElement.textContent = displayTemp + '°';
    const tempUnitSpan = tempElement.parentElement.querySelector('span:last-child');
    if (tempUnitSpan) tempUnitSpan.textContent = tempUnit;
    console.log('🌡️ Temperature updated:', displayTemp + '°C');
  }
  
  // HUMIDITY
  const humElement = document.getElementById('active-humidity');
  if (humElement) {
    humElement.textContent = Math.round(room.humidity) + '%';
  }
  
  // LIGHT LEVEL
  const lightElement = document.getElementById('active-light');
  if (lightElement) {
    const lightPercent = Math.round((room.light_level / 4095) * 100);
    lightElement.textContent = lightPercent + '%';
  }
  
  // OCCUPANCY
  const occupantElement = document.getElementById('active-occupants');
  if (occupantElement) {
    const occupants = room.motion_detected ? '01' : '00';
    occupantElement.textContent = occupants;
  }
  
  // STATUS INDICATOR
  const statusIndicator = document.getElementById('active-room-indicator');
  const statusText = document.getElementById('active-room-status');
  
  if (room.motion_detected) {
    if (statusIndicator) {
      statusIndicator.style.background = '#22c55e';
      statusIndicator.style.boxShadow = '0 0 12px #22c55e';
    }
    if (statusText) statusText.textContent = 'Occupied • Active now';
  } else {
    if (statusIndicator) {
      statusIndicator.style.background = '#94a3b8';
      statusIndicator.style.boxShadow = 'none';
    }
    if (statusText) statusText.textContent = 'Vacant • Inactive';
  }
  
  // VISUAL HIGHLIGHT
  const activeRoomCard = document.getElementById('active-room-card');
  if (activeRoomCard) {
    activeRoomCard.style.transition = 'all 0.3s ease';
    activeRoomCard.style.transform = 'scale(1.02)';
    setTimeout(() => {
      activeRoomCard.style.transform = 'scale(1)';
    }, 300);
  }
}

function updateRoomCards(rooms) {
  const roomsContainer = document.querySelector('.roomsContainer');
  if (!roomsContainer) return;
  
  roomsContainer.innerHTML = '';
  
  rooms.forEach(room => {
    const tempC = Math.round(room.temperature);
    const roomCard = createRoomCard(room, tempC);
    roomsContainer.appendChild(roomCard);
  });
}

function createRoomCard(room, tempC) {
  const card = document.createElement('div');
  card.className = 'single-room mini-card';
  card.style.opacity = '0';
  card.style.transform = 'translateY(20px)';
  
  const roomName = ROOM_NAMES[room.room] || room.room;
  
  card.innerHTML = `
    <div class="roomName"><h1>${roomName}</h1></div>
    <hr>
    <div class="allstats">
      <div class="singleStat">
        <h3>${tempC}°</h3>
        <span>Temp</span>
      </div>
      <div class="SingleStat">
        <h3>${Math.round(room.humidity)}%</h3>
        <span>Hum</span>
      </div>
      <div class="SingleStat">
        <h3>${room.motion_detected ? '01' : '00'}</h3>
        <span>Per</span>
      </div>
    </div>
  `;
  
  requestAnimationFrame(() => {
    card.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
    card.style.opacity = '1';
    card.style.transform = 'translateY(0)';
  });
  
  return card;
}

function updateRoomStats(rooms) {
  rooms.forEach(room => {
    const statsElement = document.querySelector(`[data-room="${room.room}"]`);
    if (statsElement) {
      const tempC = Math.round(room.temperature);
      const tempValue = statsElement.querySelector('.temp-value');
      const humValue = statsElement.querySelector('.hum-value');
      if (tempValue) tempValue.textContent = tempC + '°';
      if (humValue) humValue.textContent = Math.round(room.humidity) + '%';
    }
  });
}

function updateNotifications(alerts) {
  const tableContainer = document.getElementById('notifications-container');
  if (!tableContainer) return;

  const existingItems = tableContainer.querySelectorAll('.table-item, .no-notifications, .loading-placeholder');
  existingItems.forEach(item => item.remove());

  if (!alerts || alerts.length === 0) {
    showNoNotifications(tableContainer);
    return;
  }

  alerts.forEach((alert, index) => {
    const alertElement = createAlertElement(alert);
    tableContainer.appendChild(alertElement);

    setTimeout(() => {
      alertElement.style.opacity = '1';
      alertElement.style.transform = 'translateX(0)';
    }, index * 100);
  });
}

function createAlertElement(alert) {
  const div = document.createElement('div');
  div.className = 'table-item';
  div.style.opacity = '0';
  div.style.transform = 'translateX(-20px)';
  div.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
  div.style.cursor = 'pointer';
  
  const severityClass = alert.severity === 'critical' ? '' : 'mild';
  const severityColor = alert.severity === 'critical' ? '' : 'stat-mild';
  
  div.innerHTML = `
    <div class="warnings">
      <div class="sensor">${alert.alert_type}</div>
      <span>|</span>
    </div>
    <div class="warnings">
      <div class="reading">${alert.room || 'System'}</div>
      <span>|</span>
    </div>
    <div class="description">
      <span class="dot ${severityClass}"></span>
      <div class="status ${severityColor}">${capitalize(alert.severity)}</div>
    </div>
  `;
  
  div.addEventListener('click', function() {
    this.style.opacity = '0';
    this.style.transform = 'translateX(20px)';
    setTimeout(() => this.remove(), 400);
  });
  
  return div;
}

// ====================================================================
// CHART VISUALIZATION - CELSIUS
// ====================================================================

function initChartVisualization() {
  const canvas = document.getElementById('liveChart');
  if (!canvas) {
    console.warn('Chart canvas not found');
    return;
  }
  
  const ctx = canvas.getContext('2d');
  
  chartInstance = new Chart(ctx, {
    type: 'line',
    data: {
      labels: [],
      datasets: [{
        label: 'Temperature (°C)',  // Changed to Celsius
        data: [],
        borderColor: '#000000',
        backgroundColor: 'transparent',
        borderWidth: 2,
        tension: 0.4,
        pointRadius: 4,
        pointBackgroundColor: '#000000',
        pointBorderColor: '#000000',
        pointHoverRadius: 6
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: true,
          position: 'top',
          labels: {
            color: '#000000',
            font: {
              family: 'Inter',
              size: 14,
              weight: '600'
            }
          }
        }
      },
      scales: {
        y: {
          beginAtZero: false,
          ticks: {
            color: '#000000',
            font: { family: 'Inter', size: 12 }
          },
          grid: { display: false },
          border: { display: true, color: '#000000' }
        },
        x: {
          ticks: {
            color: '#000000',
            font: { family: 'Inter', size: 12 }
          },
          grid: { display: false },
          border: { display: true, color: '#000000' }
        }
      }
    }
  });
  
  fetchChartData();
}

function updateChart(data) {
  if (!chartInstance || !data || data.length === 0) return;
  
  const labels = data.map(d => d.time);
  const temperatures = data.map(d => Math.round(d.temperature)); // Celsius, no conversion
  
  chartInstance.data.labels = labels;
  chartInstance.data.datasets[0].data = temperatures;
  chartInstance.update('none');
}

// ====================================================================
// NAVBAR SCROLL ANIMATION
// ====================================================================

function initNavbarScrollAnimation() {
  window.addEventListener("scroll", () => {
    const navbar = document.getElementById("navbar");
    if (!navbar) return;

    const scrollPosition = window.scrollY;
    const baseOpacity = 0.05;
    const maxOpacity = 1;
    const scrollFactor = Math.min(scrollPosition / 300, 1);
    const opacity = baseOpacity + (maxOpacity - baseOpacity) * scrollFactor;

    const backgroundColor = `rgba(255, 255, 255, ${opacity})`;
    const navbarContent = navbar.querySelector('.navbar-content');

    if (navbarContent) {
      navbarContent.style.background = backgroundColor;
      const blurAmount = 10 + (scrollPosition / 20);
      navbarContent.style.backdropFilter = `blur(${Math.min(blurAmount, 30)}px)`;
      navbarContent.style.webkitBackdropFilter = `blur(${Math.min(blurAmount, 30)}px)`;
      const shadowOpacity = scrollFactor * 0.2;
      navbarContent.style.boxShadow = `0 4px 16px rgba(0, 0, 0, ${shadowOpacity})`;
    }

    const links = navbar.querySelectorAll('a');
    const userNameH3 = navbar.querySelector('.user_name h3');
    const userNameSpan = navbar.querySelector('.user_name span');
    const linkColor = `rgba(${255 - (255 * scrollFactor)}, ${255 - (255 * scrollFactor)}, ${255 - (255 * scrollFactor)}, 1)`;

    links.forEach(link => {
      if (!link.parentElement.classList.contains('active')) {
        link.style.color = linkColor;
      }
    });

    const activeLink = navbar.querySelector('li.active a');
    if (activeLink) {
      const activeBgColor = `rgba(${255 - (255 * scrollFactor)}, ${255 - (255 * scrollFactor)}, ${255 - (255 * scrollFactor)}, 0.15)`;
      activeLink.style.background = activeBgColor;
      activeLink.style.color = scrollFactor > 0.5 ? '#ffffff' : linkColor;
    }

    if (userNameH3) userNameH3.style.color = linkColor;
    if (userNameSpan) {
      const spanColor = `rgba(${160 - (65 * scrollFactor)}, ${160 - (65 * scrollFactor)}, ${160 - (65 * scrollFactor)}, 1)`;
      userNameSpan.style.color = spanColor;
    }

    if (scrollPosition > 100) {
      navbar.style.paddingTop = '2rem';
    } else {
      navbar.style.paddingTop = '5rem';
    }
  });
}

// ====================================================================
// CARD INTERACTIONS
// ====================================================================

function initCardInteractions() {
  const cards = document.querySelectorAll('.card');

  cards.forEach(card => {
    card.addEventListener('mouseenter', function() {
      this.style.transform = 'translateY(-6px) scale(1.01)';
    });

    card.addEventListener('mouseleave', function() {
      this.style.transform = 'translateY(0) scale(1)';
    });

    card.addEventListener('click', function(e) {
      if (e.target.tagName === 'BUTTON') return;
      console.log('Card clicked:', this.querySelector('h2')?.textContent);
    });
  });
}

// ====================================================================
// NOTIFICATION SYSTEM
// ====================================================================

function initNotificationSystem() {
  fetchAlerts();
}

function showNoNotifications(container) {
  const existing = container.querySelector('.no-notifications');
  if (existing) return;
  
  const msg = document.createElement('div');
  msg.className = 'no-notifications';
  msg.textContent = 'No new notifications';
  msg.style.color = '#999';
  msg.style.textAlign = 'center';
  msg.style.padding = '1rem';
  msg.style.opacity = '0';
  msg.style.transition = 'opacity 0.4s ease';

  container.appendChild(msg);

  requestAnimationFrame(() => {
    msg.style.opacity = '1';
  });
}

// ====================================================================
// UTILITY FUNCTIONS
// ====================================================================

function capitalize(str) {
  return str.charAt(0).toUpperCase() + str.slice(1);
}

function showError(message) {
  console.error(message);
}

// ====================================================================
// CLEANUP ON PAGE UNLOAD
// ====================================================================

window.addEventListener('beforeunload', () => {
  if (updateTimer) {
    clearInterval(updateTimer);
  }
});
