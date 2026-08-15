<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OmniShorts — Upload Once. It Publishes Daily.</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://db.onlinewebfonts.com/c/8cb707a9b8a73f8a7403336b861c3074?family=BubbledotICG-FinePos" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <style>
        :root {
            --bg: #000000;
            --panel: #0b0b0d;
            --panel-2: #101013;
            --text: #ffffff;
            --muted: #8e8e8e;
            --nav-text: #2e2e2e;
            --pill-dark: #28282a;
            --sign-in-text: #c8c8c8;
            --nav-shadow: 0 4px 14px rgba(0, 0, 0, 0.16);
            --trust-bg: #28282a;
            --trust-border: rgba(255, 255, 255, 0.4);
            --trust-text: #c4c2c3;
            --border: rgba(255, 255, 255, 0.08);
            --font-sans: "Inter", "Segoe UI", system-ui, sans-serif;
            --font-display: "BubbledotICG-FinePos", "Geist Pixel Circle", monospace;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        html { scroll-behavior: smooth; }

        html, body {
            background: var(--bg);
            color: var(--text);
            font-family: var(--font-sans);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        body { overflow-x: hidden; }

        a { color: inherit; text-decoration: none; }

        /* ---------- Background video (fixed, hero viewport) ---------- */
        .bg {
            position: fixed;
            inset: 0;
            background: #000;
            overflow: hidden;
            z-index: 0;
        }

        .bg-video {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            pointer-events: none;
        }

        .page {
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            min-height: 100vh;
            min-height: 100dvh;
            padding: clamp(16px, 2.4vh, 28px) clamp(14px, 3vw, 32px);
        }

        /* ---------- Shared entrance ---------- */
        .anim {
            opacity: 0;
            transform: translateY(22px) scale(0.98);
            filter: blur(6px);
            animation: reveal 0.85s cubic-bezier(0.22, 1, 0.36, 1) forwards;
            animation-delay: var(--d, 0s);
        }

        @keyframes reveal {
            to { opacity: 1; transform: translateY(0) scale(1); filter: blur(0); }
        }

        /* Scroll-reveal (transition-based, fired by IntersectionObserver) */
        .reveal {
            opacity: 0;
            transform: translateY(24px);
            transition: opacity 0.85s cubic-bezier(0.22, 1, 0.36, 1), transform 0.85s cubic-bezier(0.22, 1, 0.36, 1);
            transition-delay: var(--d, 0s);
        }

        .reveal.in { opacity: 1; transform: translateY(0); }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-18px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes headlineFade {
            from { opacity: 0; transform: translateY(14px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes revealPulse {
            0% { opacity: 0; transform: translateY(22px) scale(0.98); filter: blur(6px); }
            60% { opacity: 1; transform: translateY(-1px) scale(1.015); filter: blur(0); }
            100% { opacity: 1; transform: translateY(0) scale(1); filter: blur(0); }
        }

        @keyframes overlayIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes menuIn {
            from { opacity: 0; transform: translateY(-14px) scale(0.97); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        @keyframes linkIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ---------- Header ---------- */
        .site-header {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            max-width: 720px;
            gap: clamp(18px, 2.8vw, 28px);
            flex-shrink: 0;
            animation: slideDown 0.7s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        .logo {
            width: clamp(40px, 4.4vw, 46px);
            height: clamp(40px, 4.4vw, 46px);
            border-radius: 50%;
            background: #fff;
            box-shadow: var(--nav-shadow);
            display: grid;
            place-items: center;
            flex-shrink: 0;
            transition: transform 0.25s ease;
        }

        .logo svg {
            width: 72%;
            height: 72%;
            object-fit: contain;
            color: #000;
        }

        .logo:hover { transform: scale(1.04); }

        .nav-pill {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: space-around;
            max-width: 430px;
            height: clamp(44px, 5.2vw, 48px);
            padding: 4px 8px;
            border-radius: 999px;
            background: #fff;
            box-shadow: var(--nav-shadow);
        }

        .nav-link {
            position: relative;
            font-size: clamp(13px, 1.4vw, 15px);
            font-weight: 500;
            letter-spacing: -0.01em;
            color: var(--nav-text);
            opacity: 0.5;
            padding: 10px 6px 14px;
            transition: opacity 0.2s ease;
        }

        .nav-link:hover { opacity: 0.75; }

        .nav-link.active { opacity: 1; }

        .nav-link.active::after {
            content: "";
            position: absolute;
            left: 50%;
            bottom: 5px;
            transform: translateX(-50%);
            width: 3px;
            height: 3px;
            border-radius: 50%;
            background: #000;
            box-shadow: -5px 0 0 #000, 5px 0 0 #000;
        }

        .sign-in {
            height: clamp(44px, 5.2vw, 48px);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 clamp(16px, 2vw, 22px);
            border-radius: 999px;
            background: var(--pill-dark);
            color: var(--sign-in-text);
            font-size: clamp(13px, 1.4vw, 14.5px);
            font-weight: 500;
            box-shadow: var(--nav-shadow);
            flex-shrink: 0;
            transition: background 0.2s ease, color 0.2s ease, transform 0.2s ease;
        }

        .sign-in:hover {
            background: #323234;
            color: #fff;
            transform: translateY(-1px);
        }

        .burger {
            display: none;
            width: 48px;
            height: 48px;
            border: none;
            border-radius: 50%;
            background: var(--pill-dark);
            cursor: pointer;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 5px;
            flex-shrink: 0;
            transition: background 0.25s ease;
        }

        .burger span {
            width: 18px;
            height: 1.5px;
            background: #fff;
            border-radius: 2px;
            transition: transform 0.3s ease, background 0.25s ease, opacity 0.2s ease;
        }

        body.menu-open .burger { background: #fff; }
        body.menu-open .burger span { background: #000; }
        body.menu-open .burger span:nth-child(1) { transform: translateY(6.5px) rotate(45deg); }
        body.menu-open .burger span:nth-child(2) { opacity: 0; }
        body.menu-open .burger span:nth-child(3) { transform: translateY(-6.5px) rotate(-45deg); }

        /* ---------- Hero ---------- */
        .hero {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            width: 100%;
            max-width: 900px;
        }

        .trust-row {
            --trust-size: clamp(36px, 4.5vw, 42px);
            display: inline-flex;
            align-items: center;
            margin-bottom: clamp(16px, 2.5vh, 26px);
        }

        .avatar {
            width: var(--trust-size);
            height: var(--trust-size);
            border-radius: 50%;
            background: var(--trust-bg);
            border: 1px solid var(--trust-border);
            padding: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.35s ease;
        }

        .avatar .fa-brands {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #111;
            font-size: calc(var(--trust-size) * 0.34);
        }

        .avatar.a1 { z-index: 1; }
        .avatar.a2 { z-index: 2; margin-left: calc(var(--trust-size) * -0.42); }
        .avatar.a3 { z-index: 4; margin-left: calc(var(--trust-size) * -0.42); }
        .avatar.a1:hover { transform: translateY(-2px); }
        .avatar.a2:hover { transform: translateY(-4px); }
        .avatar.a3:hover { transform: translateY(-2px); }

        .trust-pill {
            height: var(--trust-size);
            display: inline-flex;
            align-items: center;
            margin-left: calc(var(--trust-size) * -0.42);
            padding-left: calc(var(--trust-size) * 0.58);
            padding-right: calc(var(--trust-size) * 0.42);
            border-radius: 999px;
            background: var(--trust-bg);
            border: 1px solid var(--trust-border);
            color: var(--trust-text);
            font-size: clamp(12px, 1.4vw, 13.5px);
            font-weight: 500;
            white-space: nowrap;
            z-index: 5;
        }

        .headline {
            font-family: var(--font-display);
            font-weight: 400;
            color: #fff;
            font-size: clamp(28px, 6.2vw, 80px);
            line-height: 1.12;
            letter-spacing: -0.04em;
            white-space: nowrap;
            overflow: hidden;
        }

        .headline.anim { animation: none; opacity: 1; transform: none; filter: none; }

        .headline span {
            display: block;
            opacity: 0;
            transform: translateY(14px);
            animation: headlineFade 0.85s cubic-bezier(0.22, 1, 0.36, 1) forwards;
            animation-delay: var(--d, 0s);
        }

        .subhead {
            max-width: min(500px, 92%);
            font-size: clamp(calc(13.5px + 2pt), calc(1.55vw + 2pt), calc(16.5px + 2pt));
            color: #d0d0d0;
            opacity: 0.8;
            line-height: 1.55;
            font-weight: 400;
            margin-top: clamp(12px, 1.8vh, 20px);
        }

        .cta {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-top: clamp(18px, 2.6vh, 28px);
            background: #fff;
            color: #000;
            font-family: var(--font-sans);
            font-weight: 600;
            font-size: clamp(13.5px, 1.5vw, 14.5px);
            padding: clamp(11px, 1.6vh, 13px) clamp(22px, 3vw, 28px);
            border-radius: 999px;
            box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.15), 0 0 22px rgba(255, 255, 255, 0.32), 0 0 44px rgba(255, 255, 255, 0.12);
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }

        .cta:hover {
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.25), 0 0 34px rgba(255, 255, 255, 0.5), 0 0 64px rgba(255, 255, 255, 0.22);
        }

        .cta.anim { animation: revealPulse 0.85s cubic-bezier(0.22, 1, 0.36, 1) forwards; }

        .hero-cta-row { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; justify-content: center; }

        .cta-ghost {
            margin-top: clamp(18px, 2.6vh, 28px);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(40, 40, 42, 0.85);
            color: #e6e6e6;
            font-weight: 500;
            font-size: clamp(13px, 1.5vw, 14px);
            padding: clamp(11px, 1.6vh, 13px) clamp(20px, 2.8vw, 26px);
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 999px;
            backdrop-filter: blur(6px);
            transition: background 0.25s ease, border-color 0.25s ease, transform 0.25s ease;
        }

        .cta-ghost:hover { background: #323234; border-color: rgba(255, 255, 255, 0.5); transform: translateY(-1px); }

        /* ---------- Stats footer ---------- */
        .stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: clamp(12px, 2vw, 28px);
            width: 100%;
            max-width: 920px;
            flex-shrink: 0;
        }

        .stat {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
            text-align: center;
        }

        .stat-icon {
            font-family: var(--font-display);
            font-size: clamp(22px, 3vw, 33px);
            color: #fff;
            line-height: 1;
            margin-bottom: 4px;
        }

        .stat-value {
            font-family: var(--font-sans);
            font-weight: 600;
            font-size: clamp(18px, 2.2vw, 26px);
            color: #fff;
            letter-spacing: -0.025em;
            font-variant-numeric: tabular-nums;
            line-height: 1;
        }

        .stat-label {
            font-family: var(--font-sans);
            font-size: clamp(11px, 1.2vw, 12.5px);
            color: var(--muted);
            margin-top: 4px;
        }

        .scroll-hint {
            position: absolute;
            bottom: 8px;
            left: 50%;
            transform: translateX(-50%);
            color: rgba(255, 255, 255, 0.45);
            font-size: 11px;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            z-index: 1;
        }

        .scroll-hint svg { animation: bob 1.8s ease-in-out infinite; }
        @keyframes bob { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(5px); } }

        /* ---------- Sections below hero ---------- */
        .content {
            position: relative;
            z-index: 2;
            background: #000;
        }

        .section {
            max-width: 1100px;
            margin: 0 auto;
            padding: clamp(64px, 10vh, 120px) clamp(14px, 3vw, 32px);
        }

        .section-label {
            font-family: var(--font-display);
            font-size: clamp(14px, 1.6vw, 18px);
            color: #fff;
            letter-spacing: 0.02em;
            margin-bottom: 12px;
        }

        .section-title {
            font-size: clamp(28px, 4.4vw, 52px);
            font-weight: 800;
            letter-spacing: -0.03em;
            line-height: 1.08;
            max-width: 720px;
        }

        .section-desc {
            margin-top: 16px;
            max-width: 620px;
            color: var(--muted);
            font-size: clamp(15px, 1.5vw, 17px);
            line-height: 1.65;
        }

        .center { text-align: center; }
        .center .section-title, .center .section-desc { margin-left: auto; margin-right: auto; }

        /* About */
        .about-grid {
            display: grid;
            grid-template-columns: 1.15fr 1fr;
            gap: clamp(28px, 4vw, 56px);
            margin-top: 44px;
            align-items: start;
        }

        .about-copy p { color: var(--muted); font-size: 15.5px; line-height: 1.7; margin-bottom: 16px; }
        .about-copy p strong { color: #fff; font-weight: 600; }

        .about-points { display: flex; flex-direction: column; gap: 14px; }

        .about-point {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 16px 18px;
        }

        .about-point .pt-icon {
            flex-shrink: 0;
            width: 38px;
            height: 38px;
            border-radius: 12px;
            background: #fff;
            color: #000;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .about-point h4 { font-size: 15px; font-weight: 600; margin-bottom: 3px; }
        .about-point p { font-size: 13.5px; color: var(--muted); line-height: 1.55; }

        /* Steps */
        .steps {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
            margin-top: 44px;
        }

        .step-card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 28px 24px;
            transition: transform 0.3s ease, border-color 0.3s ease;
        }

        .step-card:hover { transform: translateY(-4px); border-color: rgba(255, 255, 255, 0.18); }

        .step-num {
            font-family: var(--font-display);
            font-size: 30px;
            color: #fff;
            margin-bottom: 14px;
        }

        .step-card h3 { font-size: 17px; font-weight: 700; margin-bottom: 8px; }
        .step-card p { font-size: 13.5px; color: var(--muted); line-height: 1.6; }

        /* Features */
        .features-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
            margin-top: 44px;
        }

        .feature {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 26px 24px;
            transition: transform 0.3s ease, border-color 0.3s ease;
        }

        .feature:hover { transform: translateY(-4px); border-color: rgba(255, 255, 255, 0.18); }

        .feature-icon {
            width: 46px;
            height: 46px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            margin-bottom: 18px;
        }

        .feature h3 { font-size: 16px; font-weight: 700; margin-bottom: 7px; }
        .feature p { font-size: 13.5px; color: var(--muted); line-height: 1.6; }

        /* Pricing */
        .pricing-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
            margin-top: 44px;
            align-items: stretch;
        }

        .plan {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 22px;
            padding: 30px 26px;
            display: flex;
            flex-direction: column;
        }

        .plan.highlight {
            background: var(--panel-2);
            border-color: rgba(255, 255, 255, 0.28);
            box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.12), 0 24px 60px -24px rgba(255, 255, 255, 0.15);
        }

        .plan-name { font-size: 14px; font-weight: 600; color: #fff; text-transform: uppercase; letter-spacing: 0.08em; }
        .plan-price { font-size: 40px; font-weight: 800; letter-spacing: -0.03em; margin: 14px 0 4px; }
        .plan-price span { font-size: 15px; font-weight: 500; color: var(--muted); letter-spacing: 0; }
        .plan-desc { font-size: 13.5px; color: var(--muted); line-height: 1.6; margin-bottom: 22px; }

        .plan ul { list-style: none; display: flex; flex-direction: column; gap: 10px; margin-bottom: 26px; }

        .plan li { display: flex; align-items: center; gap: 10px; font-size: 13.5px; color: #cfcfcf; }
        .plan li i { color: #fff; font-size: 11px; width: 16px; height: 16px; border-radius: 50%; background: var(--pill-dark); display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }

        .plan .btn { margin-top: auto; }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 20px;
            border-radius: 999px;
            font-family: var(--font-sans);
            font-weight: 600;
            font-size: 14px;
            transition: transform 0.25s ease, background 0.25s ease, color 0.25s ease, box-shadow 0.25s ease;
        }

        .btn:hover { transform: translateY(-2px); }

        .btn-white { background: #fff; color: #000; box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.15), 0 0 22px rgba(255, 255, 255, 0.22); }
        .btn-white:hover { box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.3), 0 0 34px rgba(255, 255, 255, 0.42); }

        .btn-dark { background: var(--pill-dark); color: #e6e6e6; border: 1px solid rgba(255, 255, 255, 0.14); }
        .btn-dark:hover { background: #323234; color: #fff; }

        /* FAQ */
        .faq-list { max-width: 720px; margin: 40px auto 0; display: flex; flex-direction: column; gap: 12px; }

        .faq-item {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
        }

        .faq-q {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            background: transparent;
            border: none;
            color: #fff;
            font-family: var(--font-sans);
            font-size: 15px;
            font-weight: 600;
            text-align: left;
            padding: 18px 20px;
            cursor: pointer;
        }

        .faq-q i { transition: transform 0.3s ease; color: var(--muted); font-size: 14px; }

        .faq-item.open .faq-q i { transform: rotate(45deg); }

        .faq-a { max-height: 0; overflow: hidden; transition: max-height 0.35s cubic-bezier(0.22, 1, 0.36, 1); }
        .faq-item.open .faq-a { max-height: 300px; }
        .faq-a p { padding: 0 20px 18px; font-size: 14px; color: var(--muted); line-height: 1.65; }

        /* Contact CTA */
        .contact-band {
            text-align: center;
            padding: clamp(70px, 12vh, 130px) clamp(14px, 3vw, 32px);
            background: linear-gradient(180deg, #000 0%, #0a0a0c 100%);
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
        }

        .contact-band .cta {
            margin-top: 34px;
            font-size: clamp(15px, 1.6vw, 17px);
            padding: clamp(14px, 2vh, 17px) clamp(30px, 4vw, 44px);
        }

        .contact-mail { margin-top: 18px; font-size: 14px; color: var(--muted); }
        .contact-mail a { color: #fff; border-bottom: 1px solid rgba(255, 255, 255, 0.3); padding-bottom: 1px; }
        .contact-mail a:hover { border-color: #fff; }

        /* Footer */
        .site-footer {
            max-width: 1100px;
            margin: 0 auto;
            padding: 44px clamp(14px, 3vw, 32px) 36px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }

        .site-footer .brand { display: flex; align-items: center; gap: 12px; }

        .site-footer .brand .logo { width: 34px; height: 34px; }
        .site-footer .brand .logo svg { width: 62%; height: 62%; }

        .site-footer .brand span { font-weight: 700; letter-spacing: -0.01em; }

        .footer-links { display: flex; align-items: center; gap: 20px; font-size: 13.5px; color: var(--muted); }
        .footer-links a { transition: color 0.2s ease; }
        .footer-links a:hover { color: #fff; }

        .footer-copy { width: 100%; text-align: center; margin-top: 26px; padding-top: 22px; border-top: 1px solid var(--border); font-size: 12.5px; color: #5c5c5e; }

        /* ---------- Mobile menu ---------- */
        .overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.62);
            -webkit-backdrop-filter: blur(6px);
            backdrop-filter: blur(6px);
            opacity: 0;
            pointer-events: none;
            z-index: 20;
        }

        .overlay.open { animation: overlayIn 0.28s ease forwards; pointer-events: auto; }

        .mobile-menu {
            position: fixed;
            top: clamp(16px, 2.4vh, 28px);
            left: 50%;
            transform: translateX(-50%);
            width: min(400px, calc(100% - 32px));
            background: #fff;
            border-radius: 28px;
            padding: 22px 18px 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.45);
            z-index: 30;
            display: flex;
            flex-direction: column;
            gap: 2px;
            max-height: calc(100dvh - 60px);
            overflow-y: auto;
        }

        .mobile-menu[hidden] { display: none; }
        .mobile-menu.open { animation: menuIn 0.38s cubic-bezier(0.22, 1, 0.36, 1) both; }

        .m-link {
            position: relative;
            display: block;
            text-align: center;
            padding: 13px 8px;
            color: var(--nav-text);
            font-size: 15px;
            font-weight: 500;
            border-radius: 12px;
            opacity: 0;
        }

        .mobile-menu.open .m-link { animation: linkIn 0.4s cubic-bezier(0.22, 1, 0.36, 1) forwards; }
        .mobile-menu.open .m-link:nth-child(1) { animation-delay: 0.05s; }
        .mobile-menu.open .m-link:nth-child(2) { animation-delay: 0.1s; }
        .mobile-menu.open .m-link:nth-child(3) { animation-delay: 0.15s; }
        .mobile-menu.open .m-link:nth-child(4) { animation-delay: 0.2s; }
        .mobile-menu.open .m-link:nth-child(5) { animation-delay: 0.25s; }
        .mobile-menu.open .m-link:nth-child(6) { animation-delay: 0.3s; }
        .mobile-menu.open .m-link:nth-child(7) { animation-delay: 0.35s; }

        .m-link.active { opacity: 1; }

        .m-link.active::after {
            content: "";
            position: absolute;
            left: 50%;
            bottom: 8px;
            transform: translateX(-50%);
            width: 3px;
            height: 3px;
            border-radius: 50%;
            background: #000;
            box-shadow: -5px 0 0 #000, 5px 0 0 #000;
        }

        .m-signin {
            margin-top: 8px;
            display: block;
            width: 100%;
            text-align: center;
            padding: 13px;
            border-radius: 14px;
            background: var(--pill-dark);
            color: #fff;
            font-size: 15px;
            font-weight: 600;
            opacity: 0;
        }

        .mobile-menu.open .m-signin { animation: linkIn 0.4s cubic-bezier(0.22, 1, 0.36, 1) 0.4s forwards; }

        /* ---------- Responsive ---------- */
        @media (max-width: 900px) {
            .features-grid, .steps, .pricing-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 720px) {
            .nav-pill, .sign-in { display: none; }
            .site-header { justify-content: space-between; }
            .logo { width: 48px; height: 48px; }
            .burger { display: flex; }
            .stats { grid-template-columns: repeat(2, 1fr); gap: clamp(18px, 4vw, 28px) clamp(12px, 3vw, 24px); }
            .headline { font-size: clamp(26px, 8.6vw, 44px); line-height: 1.05; letter-spacing: -0.08em; }
            .trust-row { --trust-size: 34px; }
            .trust-pill { font-size: 12px; }
            .about-grid { grid-template-columns: 1fr; }
            .features-grid, .steps, .pricing-grid { grid-template-columns: 1fr; }
            .site-footer { flex-direction: column; text-align: center; }
            .footer-links { flex-wrap: wrap; justify-content: center; }
        }

        @media (max-width: 420px) {
            .headline { letter-spacing: -0.09em; line-height: 1.04; }
        }

        @media (max-height: 700px) {
            .trust-row { margin-bottom: 10px; }
            .subhead { margin-top: 8px; }
            .cta { margin-top: 14px; }
            .stat-icon { font-size: clamp(18px, 3vw, 24px); }
            .stat-value { font-size: clamp(15px, 2.2vw, 20px); }
        }

        /* ---------- Reduced motion ---------- */
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            .anim, .cta.anim, .headline span, .site-header, .overlay.open, .mobile-menu.open, .m-link, .m-signin {
                animation: none !important;
                opacity: 1 !important;
                transform: none !important;
                filter: none !important;
            }
            .reveal { opacity: 1 !important; transform: none !important; transition: none !important; }
            .scroll-hint svg { animation: none; }
        }
    </style>
</head>
<body>
    <!-- Background video (fixed) -->
    <div class="bg">
        <video class="bg-video" autoplay muted loop playsinline>
            <source src="https://d8j0ntlcm91z4.cloudfront.net/user_38xzZboKViGWJOttwIXH07lWA1P/hf_20260809_012548_ef22562c-c0ae-4816-ad9d-f8922af4e6a7.mp4" type="video/mp4" />
        </video>
    </div>

    <!-- First viewport -->
    <div class="page">
        <header class="site-header">
            <a class="logo" href="#top" aria-label="OmniShorts">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <polygon points="5 3 19 12 5 21 5 3"></polygon>
                </svg>
            </a>

            <nav class="nav-pill" aria-label="Main">
                <a class="nav-link active" href="#top" data-section="hero">Home</a>
                <a class="nav-link" href="#about" data-section="about">About</a>
                <a class="nav-link" href="#features" data-section="features">Features</a>
                <a class="nav-link" href="#contact" data-section="contact">Contact</a>
            </nav>

            <a class="sign-in" href="{{ route('login') }}">Sign in</a>

            <button class="burger" id="burger" aria-expanded="false" aria-controls="mobileMenu" aria-label="Menu">
                <span></span><span></span><span></span>
            </button>
        </header>

        <main class="hero" id="top">
            <div class="trust-row anim" style="--d: 0.05s">
                <span class="avatar a1"><i class="fa-brands fa-microsoft" aria-hidden="true"></i></span>
                <span class="avatar a2"><i class="fa-brands fa-amazon" aria-hidden="true"></i></span>
                <span class="avatar a3"><i class="fa-brands fa-google" aria-hidden="true"></i></span>
                <span class="trust-pill">Trusted by 2,000+ Creators</span>
            </div>

            <h1 class="headline anim" aria-label="Upload Once. It Publishes Daily.">
                <span style="--d: 0.12s">Upload Once.</span>
                <span style="--d: 0.3s">It Publishes Daily.</span>
            </h1>

            <p class="subhead anim" style="--d: 0.28s">
                Drop your whole reel bundle, set a daily posting cron per channel, and every short goes live on YouTube automatically — no manual posting.
            </p>

            <div class="hero-cta-row">
                <a class="cta anim" style="--d: 0.4s" href="{{ route('register') }}">Get Started</a>
                <a class="cta-ghost anim" style="--d: 0.46s" href="#features">See How It Works</a>
            </div>
        </main>

        <footer class="stats">
            <div class="stat anim" style="--d: 0.5s">
                <span class="stat-icon">&lt;</span>
                <span class="stat-value" data-target="120" data-decimals="0" data-suffix="ms">0ms</span>
                <span class="stat-label">Publish Latency</span>
            </div>
            <div class="stat anim" style="--d: 0.58s">
                <span class="stat-icon">%</span>
                <span class="stat-value" data-target="99.99" data-decimals="2" data-suffix="%">0%</span>
                <span class="stat-label">Auto-publish Uptime</span>
            </div>
            <div class="stat anim" style="--d: 0.66s">
                <span class="stat-icon">*</span>
                <span class="stat-value" data-target="24" data-decimals="0" data-suffix="/7">0/7</span>
                <span class="stat-label">Autonomous Runtime</span>
            </div>
            <div class="stat anim" style="--d: 0.74s">
                <span class="stat-icon">#</span>
                <span class="stat-value" data-target="2.4" data-decimals="1" data-suffix="M">0M</span>
                <span class="stat-label">Reels Queued</span>
            </div>
        </footer>

        <a class="scroll-hint" href="#about" aria-label="Scroll to About">
            <span>Scroll</span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
        </a>
    </div>

    <!-- Sections below -->
    <div class="content">
        <!-- About -->
        <section class="section" id="about">
            <div class="reveal" style="--d: 0s">
                <span class="section-label">/ about_us</span>
                <h2 class="section-title">Built for creators who refuse to post by hand.</h2>
            </div>
            <div class="about-grid">
                <div class="about-copy reveal" style="--d: 0.1s">
                    <p>
                        <strong>OmniShorts</strong> is an autonomous publishing engine for YouTube Shorts. We took the most tedious part of running a shorts channel — uploading, naming, scheduling, and posting every single day — and removed it entirely.
                    </p>
                    <p>
                        Upload your edited reels in bulk, set a daily <strong>posting cron</strong> for each channel, and OmniShorts queues every short into its slot and publishes it on time, every time. You keep full control: drag and drop to reschedule, pause a channel, or tweak a caption before it goes live.
                    </p>
                    <p>
                        We started OmniShorts after burning months posting reels manually across multiple channels. It became our own publishing pipeline first — then we opened it to every creator who wants the same leverage.
                    </p>
                </div>
                <div class="about-points">
                    <div class="about-point reveal" style="--d: 0.16s">
                        <span class="pt-icon"><i class="fa-solid fa-clock" aria-hidden="true"></i></span>
                        <div>
                            <h4>Set it once, forever</h4>
                            <p>Per-channel cron defines how many posts go out daily and at what times.</p>
                        </div>
                    </div>
                    <div class="about-point reveal" style="--d: 0.24s">
                        <span class="pt-icon"><i class="fa-solid fa-bolt" aria-hidden="true"></i></span>
                        <div>
                            <h4>Bulk by design</h4>
                            <p>Drop a whole bundle of edited reels — they fill your schedule automatically.</p>
                        </div>
                    </div>
                    <div class="about-point reveal" style="--d: 0.32s">
                        <span class="pt-icon"><i class="fa-brands fa-youtube" aria-hidden="true"></i></span>
                        <div>
                            <h4>Real YouTube connect</h4>
                            <p>Sign in with Google, pick your channel, grant permission. That's the whole setup.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- How it works -->
        <section class="section" id="how">
            <div class="center reveal" style="--d: 0s">
                <span class="section-label">/ how_it_works</span>
                <h2 class="section-title">Three steps. Then it runs itself.</h2>
                <p class="section-desc">From bundle to live — no scheduling apps, no reminders, no manual uploads.</p>
            </div>
            <div class="steps">
                <div class="step-card reveal" style="--d: 0.1s">
                    <div class="step-num">01</div>
                    <h3>Upload your reels</h3>
                    <p>Drop single reels or a whole bundle pack. Each file becomes a ready short with a title and caption.</p>
                </div>
                <div class="step-card reveal" style="--d: 0.2s">
                    <div class="step-num">02</div>
                    <h3>Set your channel cron</h3>
                    <p>Pick how many posts go out per day and at what times — per channel, not per video.</p>
                </div>
                <div class="step-card reveal" style="--d: 0.3s">
                    <div class="step-num">03</div>
                    <h3>It publishes itself</h3>
                    <p>The scheduler posts each short to YouTube at its slot. You just watch the views roll in.</p>
                </div>
            </div>
        </section>

        <!-- Features -->
        <section class="section" id="features">
            <div class="center reveal" style="--d: 0s">
                <span class="section-label">/ features</span>
                <h2 class="section-title">Everything you need to go hands-free.</h2>
                <p class="section-desc">One command center for the entire publishing loop.</p>
            </div>
            <div class="features-grid">
                <div class="feature reveal" style="--d: 0.05s">
                    <div class="feature-icon"><i class="fa-solid fa-boxes-stacked" aria-hidden="true"></i></div>
                    <h3>Bundle Upload</h3>
                    <p>Import hundreds of edited reels at once. They queue into your daily slots in order.</p>
                </div>
                <div class="feature reveal" style="--d: 0.1s">
                    <div class="feature-icon"><i class="fa-solid fa-calendar-clock" aria-hidden="true"></i></div>
                    <h3>Channel Cron</h3>
                    <p>Per-channel default schedule: posts per day and exact posting times.</p>
                </div>
                <div class="feature reveal" style="--d: 0.15s">
                    <div class="feature-icon"><i class="fa-solid fa-hand-pointer" aria-hidden="true"></i></div>
                    <h3>Drag &amp; Drop Calendar</h3>
                    <p>Move posts between days, or drop unscheduled reels onto the grid.</p>
                </div>
                <div class="feature reveal" style="--d: 0.2s">
                    <div class="feature-icon"><i class="fa-brands fa-google" aria-hidden="true"></i></div>
                    <h3>Google Connect</h3>
                    <p>Real OAuth — see all your YouTube channels and pick one in seconds.</p>
                </div>
                <div class="feature reveal" style="--d: 0.25s">
                    <div class="feature-icon"><i class="fa-solid fa-robot" aria-hidden="true"></i></div>
                    <h3>Auto-publish Scheduler</h3>
                    <p>A built-in engine checks every minute and publishes anything due.</p>
                </div>
                <div class="feature reveal" style="--d: 0.3s">
                    <div class="feature-icon"><i class="fa-solid fa-chart-line" aria-hidden="true"></i></div>
                    <h3>Next-slot Defaults</h3>
                    <p>Single reels default to your channel's next free cron slot — no thought required.</p>
                </div>
            </div>
        </section>

        <!-- Pricing -->
        <section class="section" id="pricing">
            <div class="center reveal" style="--d: 0s">
                <span class="section-label">/ pricing</span>
                <h2 class="section-title">Simple pricing. Serious leverage.</h2>
                <p class="section-desc">Start free, scale when your channel does.</p>
            </div>
            <div class="pricing-grid">
                <div class="plan reveal" style="--d: 0.1s">
                    <div class="plan-name">Starter</div>
                    <div class="plan-price">$0 <span>/ forever</span></div>
                    <p class="plan-desc">For your first channel and first bundle.</p>
                    <ul>
                        <li><i class="fa-solid fa-check"></i> 1 channel</li>
                        <li><i class="fa-solid fa-check"></i> 20 reels queued</li>
                        <li><i class="fa-solid fa-check"></i> 1 post / day</li>
                        <li><i class="fa-solid fa-check"></i> YouTube connect</li>
                    </ul>
                    <a class="btn btn-dark" href="{{ route('register') }}">Get Started</a>
                </div>
                <div class="plan highlight reveal" style="--d: 0.2s">
                    <div class="plan-name">Pro</div>
                    <div class="plan-price">$19 <span>/ month</span></div>
                    <p class="plan-desc">For serious shorts channels posting daily.</p>
                    <ul>
                        <li><i class="fa-solid fa-check"></i> Up to 5 channels</li>
                        <li><i class="fa-solid fa-check"></i> Unlimited reels</li>
                        <li><i class="fa-solid fa-check"></i> Up to 4 posts / day</li>
                        <li><i class="fa-solid fa-check"></i> Custom cron per channel</li>
                        <li><i class="fa-solid fa-check"></i> Drag &amp; drop calendar</li>
                    </ul>
                    <a class="btn btn-white" href="{{ route('register') }}">Start Free Trial</a>
                </div>
                <div class="plan reveal" style="--d: 0.3s">
                    <div class="plan-name">Agency</div>
                    <div class="plan-price">$49 <span>/ month</span></div>
                    <p class="plan-desc">For teams running many client channels.</p>
                    <ul>
                        <li><i class="fa-solid fa-check"></i> Unlimited channels</li>
                        <li><i class="fa-solid fa-check"></i> Team seats</li>
                        <li><i class="fa-solid fa-check"></i> Priority scheduling</li>
                        <li><i class="fa-solid fa-check"></i> Priority support</li>
                    </ul>
                    <a class="btn btn-dark" href="{{ route('register') }}">Contact Sales</a>
                </div>
            </div>
        </section>

        <!-- FAQ -->
        <section class="section" id="faq">
            <div class="center reveal" style="--d: 0s">
                <span class="section-label">/ faq</span>
                <h2 class="section-title">Questions, answered.</h2>
            </div>
            <div class="faq-list">
                <div class="faq-item reveal" style="--d: 0.05s">
                    <button class="faq-q" type="button">Does OmniShorts really upload to YouTube? <i class="fa-solid fa-plus" aria-hidden="true"></i></button>
                    <div class="faq-a"><p>The scheduled-publish engine flips queued posts to live at their slot. Real upload via the YouTube API is wired to your connected channel credentials.</p></div>
                </div>
                <div class="faq-item reveal" style="--d: 0.1s">
                    <button class="faq-q" type="button">Can I change the posting time for one reel? <i class="fa-solid fa-plus" aria-hidden="true"></i></button>
                    <div class="faq-a"><p>Yes. Every channel has a default cron, but any single post can be overridden — drag it to another day or set a custom time.</p></div>
                </div>
                <div class="faq-item reveal" style="--d: 0.15s">
                    <button class="faq-q" type="button">What happens if my cron slot is already full? <i class="fa-solid fa-plus" aria-hidden="true"></i></button>
                    <div class="faq-a"><p>New reels fall into the next free slot automatically. Nothing double-books, nothing waits for you.</p></div>
                </div>
                <div class="faq-item reveal" style="--d: 0.2s">
                    <button class="faq-q" type="button">Is my content ever re-edited or altered? <i class="fa-solid fa-plus" aria-hidden="true"></i></button>
                    <div class="faq-a"><p>Never. OmniShorts only schedules and publishes the exact files you upload. We generate titles and captions you can edit before anything goes live.</p></div>
                </div>
                <div class="faq-item reveal" style="--d: 0.25s">
                    <button class="faq-q" type="button">Can I pause a channel's cron? <i class="fa-solid fa-plus" aria-hidden="true"></i></button>
                    <div class="faq-a"><p>Yes — change or pause the per-channel schedule any time. Queued posts stay put until you resume.</p></div>
                </div>
            </div>
        </section>

        <!-- Contact -->
        <section class="contact-band" id="contact">
            <div class="reveal" style="--d: 0s">
                <span class="section-label">/ get_started</span>
                <h2 class="section-title" style="margin: 0 auto;">Your channel posts itself tomorrow.</h2>
                <div style="margin-top: 18px;">
                    <a class="cta" style="margin-top: 0; display: inline-flex;" href="{{ route('register') }}">Get Started</a>
                </div>
                <div class="contact-mail">Questions? <a href="mailto:hello@omshorts.app">hello@omshorts.app</a></div>
            </div>
        </section>

        <!-- Footer -->
        <footer class="site-footer">
            <div class="brand">
                <span class="logo">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <polygon points="5 3 19 12 5 21 5 3"></polygon>
                    </svg>
                </span>
                <span>OmniShorts</span>
            </div>
            <nav class="footer-links" aria-label="Footer">
                <a href="#about">About</a>
                <a href="#features">Features</a>
                <a href="#pricing">Pricing</a>
                <a href="#faq">FAQ</a>
                <a href="{{ route('login') }}">Sign in</a>
            </nav>
            <div class="footer-copy">© 2026 OmniShorts OS · Upload once. It publishes daily.</div>
        </footer>
    </div>

    <!-- Mobile menu -->
    <div class="overlay" id="overlay"></div>
    <div class="mobile-menu" id="mobileMenu" hidden role="dialog" aria-label="Menu">
        <a class="m-link active" href="#top">Home</a>
        <a class="m-link" href="#about">About</a>
        <a class="m-link" href="#features">Features</a>
        <a class="m-link" href="#pricing">Pricing</a>
        <a class="m-link" href="#faq">FAQ</a>
        <a class="m-link" href="#contact">Contact</a>
        <a class="m-signin" href="{{ route('login') }}">Sign in</a>
    </div>

    <script src="{{ asset('js/landing.js') }}"></script>
</body>
</html>
