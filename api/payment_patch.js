// ============================================================
// PATCH FILE — replace/add these JS functions in mem.html
// They replace the bank-transfer payment flow with Flutterwave
// ============================================================

// ── 1. Add Flutterwave script to <head> ─────────────────────
// Add this line inside your <head> tag:
// <script src="https://checkout.flutterwave.com/v3.js"></script>


// ── 2. Replace showRegistrationPaymentStep() ────────────────
// This fires right after a new member registers.
// Instead of showing bank details, it launches Flutterwave inline.
function showRegistrationPaymentStep() {
  const overlay = document.createElement('div');
  overlay.id = 'paymentScreen';
  overlay.style.cssText = 'position:fixed;inset:0;background:linear-gradient(135deg,#064E3B 0%,#0d7a55 50%,#111827 100%);display:flex;align-items:center;justify-content:center;z-index:9999;padding:20px;overflow-y:auto';
  overlay.innerHTML = `
    <div style="background:#fff;border-radius:20px;padding:40px;width:100%;max-width:520px;box-shadow:0 40px 80px rgba(0,0,0,.3)">
      <div style="text-align:center;margin-bottom:28px">
        <div style="width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,#064E3B,#0a6b52);display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:30px">🏥</div>
        <h2 style="font-family:'Playfair Display',serif;font-size:22px;font-weight:700;color:#064E3B;margin-bottom:6px">Account Created! ✅</h2>
        <p style="font-size:13px;color:#6b7280">Welcome, ${CURRENT_USER.name}. Choose your membership plan to activate your account.</p>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:24px" id="planSelector">
        <div onclick="selectPlan('monthly')" id="plan-monthly" style="border:2px solid #064E3B;border-radius:12px;padding:16px;cursor:pointer;text-align:center;background:#f0fdf4;transition:.2s">
          <div style="font-size:22px;font-weight:800;color:#064E3B">₦1,200</div>
          <div style="font-size:13px;font-weight:600;color:#374151;margin-top:4px">Monthly</div>
          <div style="font-size:11px;color:#6b7280;margin-top:2px">Billed every month</div>
        </div>
        <div onclick="selectPlan('annual')" id="plan-annual" style="border:2px solid #e5e7eb;border-radius:12px;padding:16px;cursor:pointer;text-align:center;background:#fff;transition:.2s;position:relative">
          <div style="position:absolute;top:-10px;left:50%;transform:translateX(-50%);background:#D4AF37;color:#fff;font-size:10px;font-weight:800;padding:2px 10px;border-radius:20px">SAVE ₦2,400</div>
          <div style="font-size:22px;font-weight:800;color:#064E3B">₦12,000</div>
          <div style="font-size:13px;font-weight:600;color:#374151;margin-top:4px">Annual</div>
          <div style="font-size:11px;color:#6b7280;margin-top:2px">12 months upfront</div>
        </div>
      </div>

      <div id="paymentStatus" style="display:none;margin-bottom:16px;padding:12px;border-radius:8px;font-size:13px;text-align:center"></div>

      <button id="payNowBtn" onclick="initiateFlutterwavePayment()"
        style="width:100%;padding:14px;background:#064E3B;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;transition:.2s"
        onmouseover="this.style.background='#0a6b52'" onmouseout="this.style.background='#064E3B'">
        <i class="fas fa-lock"></i> Pay with Flutterwave
      </button>
      <p style="text-align:center;font-size:11px;color:#9ca3af;margin-top:10px">
        Secured by Flutterwave · Card, Bank Transfer, USSD
      </p>

      <div style="margin-top:16px;padding-top:16px;border-top:1px solid #f3f4f6;text-align:center">
        <button onclick="proceedToPendingPortal()"
          style="background:none;border:none;font-size:12px;color:#9ca3af;cursor:pointer;text-decoration:underline">
          Pay later — enter portal (membership pending)
        </button>
      </div>
    </div>`;
  document.body.appendChild(overlay);
  window._selectedPlan = 'monthly'; // default
}

function selectPlan(plan) {
  window._selectedPlan = plan;
  ['monthly','annual'].forEach(p => {
    const el = document.getElementById('plan-'+p);
    if (p === plan) {
      el.style.borderColor = '#064E3B';
      el.style.background  = '#f0fdf4';
    } else {
      el.style.borderColor = '#e5e7eb';
      el.style.background  = '#fff';
    }
  });
}

async function initiateFlutterwavePayment() {
  const btn = document.getElementById('payNowBtn');
  const statusEl = document.getElementById('paymentStatus');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating payment link...';

  try {
    const result = await api('payment','initiate','POST',{
      user_id: CURRENT_USER.id,
      plan: window._selectedPlan || 'monthly'
    });

    window._currentTxRef = result.tx_ref;

    // Launch Flutterwave inline popup
    FlutterwaveCheckout({
      public_key: 'FLWPUBK_TEST-213ec04132197ad19b7c7489d7aaf173-X',
      tx_ref:      result.tx_ref,
      amount:      window._selectedPlan === 'annual' ? 12000 : 1200,
      currency:    'NGN',
      payment_options: 'card,banktransfer,ussd',
      customer: {
        email: CURRENT_USER.email,
        name:  CURRENT_USER.name,
      },
      customizations: {
        title:       'AHRIMPN Membership Dues',
        description: (window._selectedPlan === 'annual' ? '12-month' : '1-month') + ' membership dues',
        logo: '',
      },
      callback: async function(data) {
        // data.status will be 'successful', 'cancelled', etc.
        if (data.status === 'successful' || data.status === 'completed') {
          showPaymentStatus('loading', '⏳ Verifying payment...');
          try {
            await verifyPaymentAfterCheckout(data.transaction_id, data.tx_ref);
          } catch(e) {
            showPaymentStatus('error', '⚠️ ' + e.message + '. Contact support if funds were deducted.');
          }
        } else {
          showPaymentStatus('warning', '⚠️ Payment was not completed. Please try again.');
        }
      },
      onclose: function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-lock"></i> Pay with Flutterwave';
      }
    });

  } catch(e) {
    showPaymentStatus('error', '❌ ' + e.message);
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-lock"></i> Pay with Flutterwave';
  }
}

function showPaymentStatus(type, message) {
  const el = document.getElementById('paymentStatus');
  if (!el) return;
  el.style.display = 'block';
  const styles = {
    loading: 'background:#f0fdf4;color:#065f46;border:1px solid #bbf7d0',
    error:   'background:#fee2e2;color:#991b1b;border:1px solid #fca5a5',
    warning: 'background:#fffbeb;color:#92400e;border:1px solid #fde68a',
    success: 'background:#f0fdf4;color:#065f46;border:1px solid #bbf7d0',
  };
  el.style.cssText = 'display:block;margin-bottom:16px;padding:12px;border-radius:8px;font-size:13px;text-align:center;font-weight:600;' + styles[type];
  el.innerHTML = message;
}

async function verifyPaymentAfterCheckout(transactionId, txRef) {
  const res = await api('payment', 'verify&transaction_id=' + transactionId + '&tx_ref=' + txRef);

  if (res.status === 'success' || res.status === 'already_verified') {
    // Update current user session as active
    CURRENT_USER.active = 1;
    CURRENT_USER.membership_status = 'active';
    sessionStorage.setItem('ahrimpn_user', JSON.stringify(CURRENT_USER));

    showPaymentStatus('success', '✅ Payment verified! Membership activated.');
    setTimeout(() => {
      const screen = document.getElementById('paymentScreen');
      if (screen) screen.remove();
      launchApp();
      showToast('Welcome! Your AHRIMPN membership is now active 🎉', 'success');
    }, 1500);
  }
}


// ── 3. Replace showPaymentInstructions() ────────────────────
// This shows for members already in the portal with pending status
function showPaymentInstructions() {
  showModal('💳 Activate Membership', `
    <div style="text-align:center;padding:12px 0 20px">
      <div style="font-size:42px;margin-bottom:10px">💳</div>
      <h3 style="font-size:17px;font-weight:700;color:#064E3B;margin-bottom:6px">Complete Your Membership Payment</h3>
      <p style="font-size:13px;color:#6b7280;margin-bottom:24px">Choose a plan and pay securely via Flutterwave</p>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:20px">
      <div onclick="selectModalPlan('monthly')" id="modal-plan-monthly"
        style="border:2px solid #064E3B;border-radius:10px;padding:14px;cursor:pointer;text-align:center;background:#f0fdf4">
        <div style="font-size:20px;font-weight:800;color:#064E3B">₦1,200</div>
        <div style="font-size:12px;font-weight:600;color:#374151;margin-top:3px">Monthly</div>
      </div>
      <div onclick="selectModalPlan('annual')" id="modal-plan-annual"
        style="border:2px solid #e5e7eb;border-radius:10px;padding:14px;cursor:pointer;text-align:center;background:#fff;position:relative">
        <div style="position:absolute;top:-9px;left:50%;transform:translateX(-50%);background:#D4AF37;color:#fff;font-size:10px;font-weight:800;padding:2px 8px;border-radius:20px">SAVE ₦2,400</div>
        <div style="font-size:20px;font-weight:800;color:#064E3B">₦12,000</div>
        <div style="font-size:12px;font-weight:600;color:#374151;margin-top:3px">Annual</div>
      </div>
    </div>

    <div id="modalPayStatus" style="display:none;margin-bottom:12px;padding:10px;border-radius:8px;font-size:13px;text-align:center"></div>

    <div class="form-actions" style="justify-content:center;flex-direction:column;gap:10px">
      <button class="btn-sm btn-green" id="modalPayBtn" onclick="initiateModalPayment()" style="width:100%;display:flex;align-items:center;justify-content:center;gap:8px">
        <i class="fas fa-lock"></i> Pay Securely with Flutterwave
      </button>
      <button class="btn-sm btn-outline" onclick="closeModal()">Close</button>
    </div>
    <p style="text-align:center;font-size:11px;color:#9ca3af;margin-top:8px">Card · Bank Transfer · USSD</p>`);

  window._modalPlan = 'monthly';
}

function selectModalPlan(plan) {
  window._modalPlan = plan;
  ['monthly','annual'].forEach(p => {
    const el = document.getElementById('modal-plan-'+p);
    if (!el) return;
    if (p === plan) { el.style.borderColor='#064E3B'; el.style.background='#f0fdf4'; }
    else            { el.style.borderColor='#e5e7eb'; el.style.background='#fff'; }
  });
}

async function initiateModalPayment() {
  const btn = document.getElementById('modalPayBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
  try {
    const result = await api('payment','initiate','POST',{
      user_id: CURRENT_USER.id,
      plan: window._modalPlan || 'monthly'
    });
    FlutterwaveCheckout({
      public_key:      'FLWPUBK_TEST-213ec04132197ad19b7c7489d7aaf173-X',
      tx_ref:          result.tx_ref,
      amount:          window._modalPlan === 'annual' ? 12000 : 1200,
      currency:        'NGN',
      payment_options: 'card,banktransfer,ussd',
      customer:        { email: CURRENT_USER.email, name: CURRENT_USER.name },
      customizations:  { title: 'AHRIMPN Membership Dues', description: 'Membership dues' },
      callback: async function(data) {
        if (data.status === 'successful' || data.status === 'completed') {
          const statusEl = document.getElementById('modalPayStatus');
          if (statusEl) { statusEl.style.display='block'; statusEl.textContent='⏳ Verifying...'; statusEl.style.cssText='display:block;padding:10px;background:#f0fdf4;color:#065f46;border-radius:8px;font-size:13px;text-align:center'; }
          try {
            await verifyPaymentAfterCheckout(data.transaction_id, data.tx_ref);
            closeModal();
          } catch(e) { showToast(e.message, 'error'); }
        }
      },
      onclose: function() { btn.disabled=false; btn.innerHTML='<i class="fas fa-lock"></i> Pay Securely with Flutterwave'; }
    });
  } catch(e) {
    showToast(e.message, 'error');
    btn.disabled=false;
    btn.innerHTML='<i class="fas fa-lock"></i> Pay Securely with Flutterwave';
  }
}


// ── 4. Handle ?payment=success redirect (from Flutterwave callback) ─
// Add this call at the bottom of your DOMContentLoaded or after launchApp()
function checkPaymentRedirect() {
  const params = new URLSearchParams(window.location.search);
  const payResult = params.get('payment');
  const txRef     = params.get('ref');
  if (!payResult) return;
  // Clean URL
  window.history.replaceState({}, '', window.location.pathname);
  if (payResult === 'success') {
    setTimeout(() => showToast('Payment successful! Membership activated ✅', 'success'), 800);
    // Refresh user session
    if (TOKEN && CURRENT_USER) {
      api('members','get&id='+CURRENT_USER.id).then(u => {
        if (u) {
          CURRENT_USER = { ...CURRENT_USER, ...u };
          sessionStorage.setItem('ahrimpn_user', JSON.stringify(CURRENT_USER));
          const banner = document.getElementById('pendingBanner');
          if (banner && CURRENT_USER.active) banner.style.display = 'none';
        }
      }).catch(()=>{});
    }
  } else if (payResult === 'failed') {
    setTimeout(() => showToast('Payment was not completed. Try again from your dashboard.', 'error'), 800);
  }
}

// Call this after launchApp() completes:
// checkPaymentRedirect();
// And add it to your existing launchApp() function at the end.


// ── 5. Update pending banner button ─────────────────────────
// Change the banner button from:
//   onclick="showPaymentInstructions()"
// The function is already replaced above to use Flutterwave, so no HTML change needed.


// ── 6. Pending approvals table: show Flutterwave payment info ─
// Replace the loadPendingApprovals() table render section to show payment attempt info:
// In the <td> for "Status", replace the static badge with:
//   ${m.flw_status === 'pending' ? '<span class="badge badge-yellow">⏳ Payment Initiated</span>' :
//     m.flw_status === 'failed'  ? '<span class="badge badge-red">❌ Payment Failed</span>' :
//                                  '<span class="badge status-pending">📝 No Payment Yet</span>'}
// And in "Registered" column, add:
//   ${m.payment_initiated_at ? '<div style="font-size:10px;color:#6b7280">💳 Initiated: '+m.payment_initiated_at+'</div>' : ''}
