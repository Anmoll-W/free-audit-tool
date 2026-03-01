#!/usr/bin/env python3
"""
Injects Meta Pixel + Google Ads retargeting scaffold into each comparison page.
Per-competitor custom event on 50% scroll = distinct audience segment in Meta.
Boss/Matt: swap PIXEL_ID_HERE + GTAG_ID_HERE at deploy time.
"""

import os

COMPETITORS = {
    "lw-vs-linkbot.html": "linkbot",
    "lw-vs-linkboss.html": "linkboss",
    "lw-vs-linkilo.html": "linkilo",
    "lw-vs-linksy.html": "linksy",
    "lw-vs-ilj.html": "ilj",
    "lw-vs-yoast.html": "yoast",
    "compare-hub.html": "hub",
}

DIR = "/home/sprite/agents/glitch/builds/comparison-pages"

def pixel_snippet(competitor):
    return f"""
  <!-- ============================================================
  META PIXEL + GOOGLE ADS RETARGETING SCAFFOLD
  Boss/Matt: Replace PIXEL_ID_HERE with your Meta Pixel ID
  (15-digit number from Meta Business Manager → Events Manager).
  Replace GTAG_ID_HERE with your Google Ads tag ID (AW-XXXXXXXXXX).
  Competitor segment: {competitor}
  ============================================================ -->
  <script>
    /* META PIXEL BASE CODE */
    !function(f,b,e,v,n,t,s){{if(f.fbq)return;n=f.fbq=function(){{n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)}};if(!f._fbq)f._fbq=n;
    n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}}(window,
    document,'script','https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', 'PIXEL_ID_HERE');
    fbq('track', 'PageView');

    /* Per-competitor custom event — fires once at 50% scroll depth.
       Creates distinct audience: "{competitor}" comparison page visitors. */
    (function() {{
      var fired = false;
      window.addEventListener('scroll', function() {{
        var scrollPct = window.scrollY / (document.body.scrollHeight - window.innerHeight);
        if (!fired && scrollPct > 0.5) {{
          fired = true;
          fbq('trackCustom', 'ComparisonPageEngaged', {{
            competitor: '{competitor}',
            scroll_depth: '50pct'
          }});
        }}
      }});
    }})();

    /* GOOGLE ADS REMARKETING */
    (function() {{
      var s = document.createElement('script');
      s.async = true;
      s.src = 'https://www.googletagmanager.com/gtag/js?id=GTAG_ID_HERE';
      document.head.appendChild(s);
    }})();
    window.dataLayer = window.dataLayer || [];
    function gtag(){{dataLayer.push(arguments);}}
    gtag('js', new Date());
    gtag('config', 'GTAG_ID_HERE');
    gtag('event', 'comparison_page_engaged', {{ competitor: '{competitor}' }});
  </script>
  <noscript><img height="1" width="1" style="display:none"
    src="https://www.facebook.com/tr?id=PIXEL_ID_HERE&ev=PageView&noscript=1" /></noscript>"""

for filename, competitor in COMPETITORS.items():
    filepath = os.path.join(DIR, filename)
    if not os.path.exists(filepath):
        print(f"MISSING: {filename}")
        continue

    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()

    # Skip if already injected
    if 'PIXEL_ID_HERE' in content or "fbq('init'" in content:
        print(f"SKIP (already has pixel): {filename}")
        continue

    # Inject before </head>
    snippet = pixel_snippet(competitor)
    new_content = content.replace('</head>', snippet + '\n</head>', 1)

    if new_content == content:
        print(f"WARN (</head> not found): {filename}")
        continue

    with open(filepath, 'w', encoding='utf-8') as f:
        f.write(new_content)

    print(f"INJECTED [{competitor}]: {filename}")

print("\nDone. All pages updated. Matt: swap PIXEL_ID_HERE + GTAG_ID_HERE at deploy.")
