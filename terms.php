<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="img/campulse_favicon.png">
    <title>CampusPulse Terms of Service</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@700;800;900&display=swap"
        rel="stylesheet">

    <!-- Icons -->
    <script src="https://unpkg.com/@phosphor-icons/web"></script>

    <style>
        :root {
            --primary: #262562;
            --accent: #F0AB00;
            --bg-main: #F8F9FA;
            --card-bg: #FFFFFF;
            --text-dark: #1A1A24;
            --text-muted: #64748B;
            --border-color: #E2E8F0;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background-color: var(--bg-main);
            color: var(--text-dark);
            font-family: 'Inter', sans-serif;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }

        .header {
            background: linear-gradient(135deg, var(--primary), #1a1941);
            color: white;
            padding: 60px 24px 40px;
            text-align: center;
            border-bottom-left-radius: 32px;
            border-bottom-right-radius: 32px;
            box-shadow: 0 10px 30px rgba(38, 37, 98, 0.15);
        }

        .header-tag {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
            margin-bottom: 16px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .header h1 {
            font-family: 'Montserrat', sans-serif;
            font-size: 2rem;
            margin-bottom: 8px;
            line-height: 1.2;
        }

        .header p {
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.95rem;
        }

        .content {
            max-width: 800px;
            margin: -20px auto 40px;
            padding: 0 20px;
            position: relative;
            z-index: 10;
        }

        .policy-section {
            background: var(--card-bg);
            border-radius: 24px;
            padding: 32px 24px;
            margin-bottom: 24px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.03);
            border: 1px solid var(--border-color);
            opacity: 0;
            transform: translateY(20px);
            animation: fadeUp 0.6s ease forwards;
        }

        .policy-section:nth-child(2) {
            animation-delay: 0.1s;
        }

        .policy-section:nth-child(3) {
            animation-delay: 0.2s;
        }

        .policy-section:nth-child(4) {
            animation-delay: 0.3s;
        }

        .policy-section:nth-child(5) {
            animation-delay: 0.4s;
        }

        .policy-section:nth-child(6) {
            animation-delay: 0.5s;
        }

        .policy-section:nth-child(7) {
            animation-delay: 0.6s;
        }

        .policy-section:nth-child(8) {
            animation-delay: 0.7s;
        }

        @keyframes fadeUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--bg-main);
            padding-bottom: 16px;
        }

        .section-icon {
            width: 40px;
            height: 40px;
            background: rgba(38, 37, 98, 0.1);
            color: var(--primary);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .section-title h2 {
            font-family: 'Montserrat', sans-serif;
            font-size: 1.25rem;
            color: var(--primary);
        }

        ul {
            list-style: none;
        }

        li {
            position: relative;
            padding-left: 24px;
            margin-bottom: 16px;
            color: var(--text-muted);
            font-size: 0.95rem;
        }

        li::before {
            content: '•';
            position: absolute;
            left: 0;
            top: 0;
            color: var(--accent);
            font-size: 1.5rem;
            line-height: 1;
        }

        strong {
            color: var(--text-dark);
            font-weight: 700;
        }

        .warning-box {
            background: #FFFBEB;
            border-left: 4px solid var(--accent);
            padding: 16px;
            border-radius: 8px;
            margin: 24px 0 12px;
        }

        .warning-box h4 {
            color: #B45309;
            font-family: 'Inter', sans-serif;
            font-size: 0.95rem;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .warning-box p {
            color: #92400E;
            font-size: 0.85rem;
            margin: 0;
        }

        .footer {
            text-align: center;
            padding: 20px 24px 60px;
        }

        .accept-btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 18px 40px;
            font-size: 1.1rem;
            font-weight: 700;
            border-radius: 20px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 10px 20px rgba(38, 37, 98, 0.2);
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: 'Inter', sans-serif;
        }

        .accept-btn:active {
            transform: scale(0.96);
            box-shadow: 0 5px 10px rgba(38, 37, 98, 0.2);
        }
    </style>
</head>

<body>

    <header class="header">
        <div class="header-tag">Version 1.2 | May 2026</div>
        <h1>Passenger Terms of Use & Service Policy</h1>
        <p>Please review these terms to ensure a safe and efficient ride experience.</p>
    </header>

    <main class="content">

        <section class="policy-section">
            <div class="section-title">
                <div class="section-icon"><i class="ph-bold ph-identification-card"></i></div>
                <h2>1. Institutional Account Eligibility</h2>
            </div>
            <ul>
                <li><strong>Institutional Credentials:</strong> Access to CampusPulse is strictly restricted to active
                    students of Universiti Kuala Lumpur (UniKL). Users must authenticate using the official
                    <em>@s.unikl.edu.my</em> email domain.
                </li>
                <li><strong>Verification Compliance:</strong> Registration requires a valid 11-digit Student ID.
                    Submitting fraudulent credentials or impersonating another student will result in a permanent ban.
                </li>
                <li><strong>Non-Transferability:</strong> Your Digital Boarding Pass (QR Code) is tied to your identity.
                    Sharing, duplicating, or transferring this pass to unauthorized users is strictly prohibited.</li>
            </ul>
        </section>

        <section class="policy-section">
            <div class="section-title">
                <div class="section-icon"><i class="ph-bold ph-wallet"></i></div>
                <h2>2. Campus Credit E-Wallet & Financials</h2>
            </div>
            <ul>
                <li><strong>Finality of Transactions:</strong> All top-ups made to the Campus Credit E-Wallet are
                    <strong>final and non-refundable</strong>. Once funds are converted into Campus Credits, they cannot
                    be liquidated, withdrawn, or transferred back to external accounts.
                </li>
                <li><strong>Service Fee:</strong> All transit services are charged at a fixed rate of <strong>RM 2.00
                        per trip</strong>, deducted immediately upon booking confirmation.</li>
                <li><strong>Credit Expiry:</strong> Credits remain active as long as the student’s account is valid.
                    Upon graduation or withdrawal from UniKL, any remaining balance is forfeited.</li>
            </ul>
        </section>

        <section class="policy-section">
            <div class="section-title">
                <div class="section-icon"><i class="ph-bold ph-map-pin-line"></i></div>
                <h2>3. Geofencing & Zone Activation</h2>
            </div>
            <ul>
                <li><strong>Zone Lock Mechanism:</strong> To ensure operational efficiency and prevent remote booking
                    abuse, the application utilizes GPS-based Zone Locking.</li>
                <li><strong>Operational Radius:</strong> Booking features and live tracking are only functional when the
                    user is physically present within a recognized <strong>UniKL Operating Zone</strong>.</li>
            </ul>
        </section>

        <section class="policy-section">
            <div class="section-title">
                <div class="section-icon"><i class="ph-bold ph-clock-countdown"></i></div>
                <h2>4. Pickup Protocol</h2>
            </div>
            <ul>
                <li><strong>Location Accuracy:</strong> Users are responsible for arriving at the correct designated
                    stop. Refer to the detailed nomenclature provided in the app (e.g., <em>"Wangsa Metroview -
                        Guardhouse"</em>).</li>
                <li><strong>Boarding Window:</strong> Shuttles adhere to strict schedules. Passengers must be visible at
                    the stop at least <strong>2 minutes</strong> prior to the displayed ETA. Drivers are not authorized
                    to wait for tardy passengers.</li>
            </ul>
        </section>

        <section class="policy-section">
            <div class="section-title">
                <div class="section-icon"><i class="ph-bold ph-receipt"></i></div>
                <h2>5. Cancellation & Refund Framework</h2>
            </div>
            <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 16px;">To prevent exploitation and
                respect driver dispatch times, the following rules apply:</p>

            <div class="warning-box" style="background: #ECFDF5; border-color: #10B981;">
                <h4 style="color: #047857;"><i class="ph-bold ph-check-circle"></i> A. Full Reimbursement (100%)</h4>
                <p style="color: #065F46;">- <strong>On-Demand Timeout:</strong> Requests not accepted by a driver
                    within 5 minutes expire automatically.<br>- <strong>Standard Cancel:</strong> Scheduled bookings
                    canceled > 15 minutes before departure.<br>- <strong>Searching Phase:</strong> On-Demand requests
                    canceled while "Searching" or "Admin Review".</p>
            </div>

            <div class="warning-box">
                <h4><i class="ph-bold ph-warning-circle"></i> B. Penalty-Based Reimbursement (50%)</h4>
                <p>- <strong>Late Scheduled Cancel:</strong> Cancellations made within 15 minutes of departure incur an
                    RM 1.00 penalty.<br>- <strong>Confirmed On-Demand Cancel:</strong> Canceled after a driver is
                    confirmed incurs an RM 1.00 penalty.</p>
            </div>

            <div class="warning-box" style="background: #FEF2F2; border-color: #EF4444;">
                <h4 style="color: #B91C1C;"><i class="ph-bold ph-x-circle"></i> C. No-Show Policy (0%)</h4>
                <p style="color: #991B1B;">If a passenger fails to board the vehicle and authenticate via QR scan before
                    the vehicle departs, the fare is <strong>strictly non-refundable</strong>.</p>
            </div>
        </section>

        <section class="policy-section">
            <div class="section-title">
                <div class="section-icon"><i class="ph-bold ph-brain"></i></div>
                <h2>6. Smart Planner Disclaimer</h2>
            </div>
            <ul>
                <li><strong>Predictive Modeling:</strong> The Smart Planner utilizes traffic data to provide
                    recommendations. These calculations are a <strong>guideline only</strong>.</li>
                <li><strong>User Preference:</strong> While the system defaults to a 15-minute buffer, the final
                    responsibility for reaching physical classes on time rests with the student.</li>
            </ul>
        </section>

        <section class="policy-section">
            <div class="section-title">
                <div class="section-icon"><i class="ph-bold ph-shield-warning"></i></div>
                <h2>7. Passenger Conduct & Limits</h2>
            </div>
            <ul>
                <li><strong>Vehicle Maintenance:</strong> Eating, drinking (open containers), smoking, and vaping are
                    strictly prohibited inside all shuttles.</li>
                <li><strong>Reporting Mechanism:</strong> Drivers may log passenger misconduct. Severe violations will
                    result in <strong>Account Suspension</strong>.</li>
                <li><strong>Force Majeure:</strong> CampusPulse is not liable for delays caused by road accidents,
                    extreme weather, or mechanical failures beyond our control.</li>
            </ul>
        </section>

        <div class="footer">
            <button class="accept-btn" onclick="exitPage()">I Understand & Accept</button>
        </div>

    </main>

    <script>
        function exitPage() {
            // Communicate back to Flutter WebView
            if (window.CampusPulse) {
                window.CampusPulse.postMessage('close');
            } else {
                // Fallback for standard browsers
                window.history.back();
            }
        }
    </script>
</body>

</html>