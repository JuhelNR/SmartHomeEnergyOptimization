// ====================================================================
// HYDRAHOME CONTROL CENTER - MAIN APPLICATION
// Modern, User-Friendly Smart Home Dashboard
// ====================================================================

// ====================================================================
// INITIALIZATION - Wait for page to fully load
// ====================================================================
document.addEventListener('DOMContentLoaded', function() {

  // Initialize all features
  initNavbarScrollAnimation();
  initChartVisualization();
  initCardInteractions();
  initNotificationSystem();

});


// ====================================================================
// NAVBAR SCROLL ANIMATION
// Creates smooth transition effects as user scrolls down the page
// ====================================================================
function initNavbarScrollAnimation() {

  window.addEventListener("scroll", () => {
    const navbar = document.getElementById("navbar");

    // Safety check - exit if navbar doesn't exist
    if (!navbar) return;

    const scrollPosition = window.scrollY;

    // ================== Calculate Opacity ==================
    // Navbar starts very transparent (0.05) and becomes solid (1.0)
    // Transition happens over 300px of scrolling
    const baseOpacity = 0.05;      // Starting transparency
    const maxOpacity = 1;          // Ending transparency
    const scrollFactor = Math.min(scrollPosition / 300, 1);
    const opacity = baseOpacity + (maxOpacity - baseOpacity) * scrollFactor;


    // ================== Apply Background Color ==================
    const backgroundColor = `rgba(255, 255, 255, ${opacity})`;
    const navbarContent = navbar.querySelector('.navbar-content');

    if (navbarContent) {
      navbarContent.style.background = backgroundColor;

      // Increase blur effect for glassmorphism look
      const blurAmount = 10 + (scrollPosition / 20);
      navbarContent.style.backdropFilter = `blur(${Math.min(blurAmount, 30)}px)`;
      navbarContent.style.webkitBackdropFilter = `blur(${Math.min(blurAmount, 30)}px)`;

      // Add subtle shadow for depth
      const shadowOpacity = scrollFactor * 0.2;
      navbarContent.style.boxShadow = `0 4px 16px rgba(0, 0, 0, ${shadowOpacity})`;
    }


    // ================== Update Link Colors ==================
    // Links transition from white (on hero) to black (on white background)
    const links = navbar.querySelectorAll('a');
    const userNameH3 = navbar.querySelector('.user_name h3');
    const userNameSpan = navbar.querySelector('.user_name span');

    // Calculate color interpolation from white (255,255,255) to black (0,0,0)
    const linkColor = `rgba(${255 - (255 * scrollFactor)}, ${255 - (255 * scrollFactor)}, ${255 - (255 * scrollFactor)}, 1)`;

    // Apply color to all non-active links
    links.forEach(link => {
      if (!link.parentElement.classList.contains('active')) {
        link.style.color = linkColor;
      }
    });


    // ================== Handle Active Link Styling ==================
    const activeLink = navbar.querySelector('li.active a');
    if (activeLink) {
      // Active link background transitions with scroll
      const activeBgColor = `rgba(${255 - (255 * scrollFactor)}, ${255 - (255 * scrollFactor)}, ${255 - (255 * scrollFactor)}, 0.15)`;
      activeLink.style.background = activeBgColor;

      // Keep active link text readable (white on dark background, dark on light)
      activeLink.style.color = scrollFactor > 0.5 ? '#ffffff' : linkColor;
    }


    // ================== Update User Info Colors ==================
    if (userNameH3) {
      userNameH3.style.color = linkColor;
    }

    if (userNameSpan) {
      // Email stays slightly muted for visual hierarchy
      const spanColor = `rgba(${160 - (65 * scrollFactor)}, ${160 - (65 * scrollFactor)}, ${160 - (65 * scrollFactor)}, 1)`;
      userNameSpan.style.color = spanColor;
    }


    // ================== Compact Navbar on Scroll ==================
    // Reduce padding after 100px for more screen space
    if (scrollPosition > 100) {
      navbar.style.paddingTop = '2rem';
    } else {
      navbar.style.paddingTop = '5rem';
    }
  });
}


// ====================================================================
// CHART VISUALIZATION
// Displays temperature data using Chart.js line graph
// ====================================================================
function initChartVisualization() {

  const canvas = document.getElementById('liveChart');

  // Safety check - exit if canvas doesn't exist
  if (!canvas) {
    console.warn('Chart canvas not found. Skipping chart initialization.');
    return;
  }

  const ctx = canvas.getContext('2d');

  // ================== Chart Configuration ==================
  const liveChart = new Chart(ctx, {
    type: 'line',

    // ================== Data Configuration ==================
    data: {
      // X-axis labels (time periods)
      labels: ['12 AM', '3 AM', '6 AM', '9 AM', '12 PM', '3 PM', '6 PM', '9 PM'],

      // Dataset configuration
      datasets: [{
        label: 'Temperature (°F)',
        data: [68, 67, 66, 70, 72, 74, 73, 71],    // Temperature readings

        // Styling
        borderColor: '#000000',                      // Line color (black)
        backgroundColor: 'transparent',               // No fill under line
        borderWidth: 2,                              // Line thickness
        tension: 0.4,                                // Curve smoothness (0 = straight, 1 = very curved)

        // Point styling
        pointRadius: 4,                              // Normal point size
        pointBackgroundColor: '#000000',
        pointBorderColor: '#000000',
        pointHoverRadius: 6,                         // Larger on hover
        pointHoverBackgroundColor: '#000000',
        pointHoverBorderColor: '#000000'
      }]
    },

    // ================== Display Options ==================
    options: {
      responsive: true,                              // Adapts to container size
      maintainAspectRatio: false,                    // Allows custom height

      // Legend configuration
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

      // Axis configuration
      scales: {
        // Y-axis (vertical - temperature values)
        y: {
          beginAtZero: false,
          min: 60,
          max: 80,
          ticks: {
            color: '#000000',
            font: {
              family: 'Inter',
              size: 12
            }
          },
          grid: {
            display: false                           // Hide gridlines for clean look
          },
          border: {
            display: true,
            color: '#000000'
          }
        },

        // X-axis (horizontal - time labels)
        x: {
          ticks: {
            color: '#000000',
            font: {
              family: 'Inter',
              size: 12
            }
          },
          grid: {
            display: false                           // Hide gridlines for clean look
          },
          border: {
            display: true,
            color: '#000000'
          }
        }
      }
    }
  });

  console.log('Chart initialized successfully');
}


// ====================================================================
// CARD INTERACTIONS
// Adds smooth hover effects and click handlers to dashboard cards
// ====================================================================
function initCardInteractions() {

  const cards = document.querySelectorAll('.card');

  cards.forEach(card => {

    // ================== Hover Effect ==================
    card.addEventListener('mouseenter', function() {
      // Add subtle scale and glow effect
      this.style.transform = 'translateY(-6px) scale(1.01)';
    });

    card.addEventListener('mouseleave', function() {
      // Return to normal state
      this.style.transform = 'translateY(0) scale(1)';
    });


    // ================== Click Handler ==================
    // Optional: Add click interaction for future features
    card.addEventListener('click', function(e) {
      // Prevent if clicking on button inside card
      if (e.target.tagName === 'BUTTON') return;

      // Add ripple effect or navigate to detail view
      console.log('Card clicked:', this.querySelector('h2')?.textContent);
    });
  });

  console.log(`Initialized ${cards.length} card interactions`);
}


// ====================================================================
// NOTIFICATION SYSTEM
// Handles notification badges and alerts
// ====================================================================
function initNotificationSystem() {

  const notificationContainer = document.querySelector('.table');
  const notificationTables = document.querySelectorAll('.table-item');

  // If empty on init
  if (notificationTables.length === 0) {
    showNoNotifications(notificationContainer);
    return;
  }

  // ============ Fade-in Animation ============
  notificationTables.forEach((table, index) => {
    setTimeout(() => {
      table.style.opacity = '0';
      table.style.transform = 'translateX(-20px)';

      requestAnimationFrame(() => {
        table.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
        table.style.opacity = '1';
        table.style.transform = 'translateX(0)';
      });
    }, index * 100);
  });

  // ============ Click to Dismiss ============
  notificationTables.forEach(table => {
    table.style.cursor = 'pointer';

    table.addEventListener('click', function () {
      this.style.opacity = '0';
      this.style.transform = 'translateX(20px)';

      setTimeout(() => {
        this.remove();

        // Check if it's empty after removal
        if (notificationContainer.querySelectorAll('.table-item').length === 0) {
          showNoNotifications(notificationContainer);
        }

      }, 400);
    });
  });

  console.log(`Initialized ${notificationTables.length} notifications`);
}


// ============ Insert "No new notifications" ============
function showNoNotifications(container) {
  const msg = document.createElement('div');
  msg.className = 'no-notifications';
  msg.textContent = 'No new notifications';

  msg.style.color = '#999';
  msg.style.textAlign = 'center';
  msg.style.padding = '1rem';
  msg.style.opacity = '0';
  msg.style.transition = 'opacity 0.4s ease';

  container.appendChild(msg);

  // Fade it in
  requestAnimationFrame(() => {
    msg.style.opacity = '1';
  });
}






// ====================================================================
// UTILITY FUNCTIONS
// Helper functions for common operations
// ====================================================================

/**
 * Formats temperature value with degree symbol
 * @param {number} temp - Temperature value
 * @returns {string} Formatted temperature string
 */
function formatTemperature(temp) {
  return `${temp}°`;
}

/**
 * Calculates color based on temperature (blue = cold, red = hot)
 * @param {number} temp - Temperature value
 * @returns {string} RGB color string
 */
function getTemperatureColor(temp) {
  if (temp < 65) return 'rgb(59, 130, 246)';      // Blue (cold)
  if (temp < 72) return 'rgb(34, 197, 94)';       // Green (comfortable)
  if (temp < 78) return 'rgb(251, 191, 36)';      // Yellow (warm)
  return 'rgb(239, 68, 68)';                       // Red (hot)
}

/**
 * Generates a unique ID for dynamic elements
 * @returns {string} Unique identifier
 */
function generateUniqueId() {
  return `element-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
}


// ====================================================================
// FUTURE ENHANCEMENTS
// Ideas for additional features:
//
// 1. Real-time data updates via WebSocket
// 2. Voice control integration
// 3. Room scheduling and automation
// 4. Energy usage tracking
// 5. Mobile app synchronization
// 6. Multi-user support with permissions
// 7. Weather integration
// 8. Smart alerts and recommendations
// ====================================================================
