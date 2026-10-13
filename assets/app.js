/**
 * Campus Connect - Interactive Client Scripts
 * Supports payment simulation, card formatting, mobile drawer, print receipt
 */

document.addEventListener('DOMContentLoaded', () => {
  // Mobile Nav Drawer Toggle with Enhanced UX
  const toggleBtn = document.getElementById('toggle');
  const navMenu = document.getElementById('nav');

  function closeMobileNav() {
    if (navMenu && navMenu.classList.contains('open')) {
      navMenu.classList.remove('open');
      if (toggleBtn) {
        toggleBtn.innerHTML = '☰';
        toggleBtn.setAttribute('aria-expanded', 'false');
      }
    }
  }

  if (toggleBtn && navMenu) {
    toggleBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      const isOpen = navMenu.classList.toggle('open');
      toggleBtn.innerHTML = isOpen ? '✕' : '☰';
      toggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    // Close when clicking any nav link
    navMenu.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', () => {
        closeMobileNav();
      });
    });

    // Close when tapping outside
    document.addEventListener('click', (e) => {
      if (!navMenu.contains(e.target) && !toggleBtn.contains(e.target)) {
        closeMobileNav();
      }
    });

    // Close when pressing Escape key
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        closeMobileNav();
      }
    });
  }

  // Payment Method Tabs
  const paymentTabs = document.querySelectorAll('.payment-tab');
  const paymentPanels = document.querySelectorAll('.payment-panel');
  const selectedModeInput = document.getElementById('selected_payment_mode');

  if (paymentTabs.length && paymentPanels.length) {
    paymentTabs.forEach(tab => {
      tab.addEventListener('click', () => {
        paymentTabs.forEach(t => t.classList.remove('active'));
        paymentPanels.forEach(p => p.classList.remove('active'));

        tab.classList.add('active');
        const targetId = tab.getAttribute('data-target');
        const targetPanel = document.getElementById(targetId);
        if (targetPanel) {
          targetPanel.classList.add('active');
        }

        // Update hidden field
        if (selectedModeInput) {
          selectedModeInput.value = tab.getAttribute('data-mode') || 'Online UPI';
        }
      });
    });
  }

  // Interactive Card Number Formatter
  const cardInput = document.getElementById('card_number');
  const cardDisplay = document.getElementById('display_card_num');
  if (cardInput && cardDisplay) {
    cardInput.addEventListener('input', (e) => {
      let value = e.target.value.replace(/\D/g, '').substring(0, 16);
      let formatted = value.match(/.{1,4}/g)?.join(' ') || '';
      e.target.value = formatted;
      cardDisplay.textContent = formatted || '•••• •••• •••• ••••';
    });
  }

  // Interactive Card Holder Formatter
  const holderInput = document.getElementById('card_holder');
  const holderDisplay = document.getElementById('display_card_holder');
  if (holderInput && holderDisplay) {
    holderInput.addEventListener('input', (e) => {
      holderDisplay.textContent = e.target.value.toUpperCase() || 'STUDENT NAME';
    });
  }

  // Interactive Expiry Formatter
  const expiryInput = document.getElementById('card_expiry');
  const expiryDisplay = document.getElementById('display_card_expiry');
  if (expiryInput && expiryDisplay) {
    expiryInput.addEventListener('input', (e) => {
      let value = e.target.value.replace(/\D/g, '').substring(0, 4);
      if (value.length >= 2) {
        value = value.substring(0, 2) + '/' + value.substring(2);
      }
      e.target.value = value;
      expiryDisplay.textContent = value || 'MM/YY';
    });
  }

  // Print Receipt Button
  const printBtn = document.getElementById('print_receipt_btn');
  if (printBtn) {
    printBtn.addEventListener('click', () => {
      window.print();
    });
  }

  // Auto-dismiss alerts after 6 seconds
  const alerts = document.querySelectorAll('.alert');
  if (alerts.length) {
    setTimeout(() => {
      alerts.forEach(alert => {
        alert.style.transition = 'opacity 0.5s ease';
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 500);
      });
    }, 6000);
  }
});