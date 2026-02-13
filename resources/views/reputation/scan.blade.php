<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reputation Scan Flow</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600&family=Space+Mono&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f6f2ea;
            --ink: #241a13;
            --muted: #5f5449;
            --accent: #a04a2a;
            --accent-2: #2a6f5d;
            --card: #fff9f2;
            --border: #e1d6c8;
            --shadow: rgba(36, 26, 19, 0.08);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Fraunces", "Times New Roman", serif;
            background: radial-gradient(circle at top left, #fff9f0, var(--bg));
            color: var(--ink);
        }

        .wrap {
            max-width: 1100px;
            margin: 0 auto;
            padding: 40px 20px 80px;
        }

        header {
            display: grid;
            gap: 10px;
            margin-bottom: 28px;
        }

        header h1 {
            font-weight: 600;
            margin: 0;
            font-size: clamp(24px, 3vw, 34px);
        }

        header p {
            margin: 0;
            color: var(--muted);
            font-size: 16px;
        }

        .grid {
            display: grid;
            grid-template-columns: minmax(280px, 1fr) minmax(280px, 1fr);
            gap: 20px;
        }

        .panel {
            background: var(--card);
            border: 1px solid var(--border);
            box-shadow: 0 10px 25px var(--shadow);
            border-radius: 14px;
            padding: 20px;
        }

        .panel h2 {
            margin: 0 0 16px;
            font-size: 18px;
        }

        details {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px;
            background: #fffdf9;
        }

        details > summary {
            cursor: pointer;
            font-weight: 600;
            list-style: none;
        }

        details > summary::-webkit-details-marker {
            display: none;
        }

        details[open] > summary {
            margin-bottom: 10px;
        }

        label {
            display: block;
            font-size: 13px;
            color: var(--muted);
            margin-bottom: 6px;
        }

        input[type="text"],
        input[type="url"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: 14px;
            font-family: "Space Mono", "Courier New", monospace;
            background: #fffdf9;
            color: var(--ink);
        }

        .row {
            display: grid;
            gap: 12px;
            margin-bottom: 14px;
        }

        .row.inline {
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        }

        .toggle {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--muted);
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 16px;
        }

        button {
            border: none;
            border-radius: 999px;
            padding: 10px 16px;
            font-weight: 600;
            cursor: pointer;
            background: var(--accent);
            color: #fff9f2;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        button.secondary {
            background: #fff9f2;
            color: var(--accent);
            border: 1px solid var(--accent);
        }

        button:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 16px rgba(160, 74, 42, 0.2);
        }

        .status {
            font-size: 13px;
            color: var(--muted);
            margin: 4px 0 12px;
        }

        .candidates {
            display: grid;
            gap: 10px;
            margin: 12px 0 0;
        }

        .candidate {
            border: 1px dashed var(--border);
            border-radius: 12px;
            padding: 12px;
            display: grid;
            gap: 8px;
            background: #fffdf9;
        }

        .candidate h3 {
            margin: 0;
            font-size: 15px;
        }

        .candidate small {
            color: var(--muted);
        }

        pre {
            background: #1f1a14;
            color: #f7efe4;
            padding: 16px;
            border-radius: 12px;
            overflow: auto;
            font-size: 12px;
            font-family: "Space Mono", "Courier New", monospace;
        }

        @media (max-width: 900px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <header>
            <h1>Reputation Scan Flow</h1>
            <p>Start with a business name. Select a match to fetch Google Places data, or skip Places to scan directly.</p>
        </header>

        <div class="grid">
            <section class="panel">
                <h2>Scan Request</h2>
                <form id="scan-form">
                    <div class="row">
                        <label for="business_name">Business name</label>
                        <input id="business_name" name="business_name" type="text" placeholder="Acme Plumbing" required>
                    </div>
                    <div class="row inline">
                        <div>
                            <label for="location">Location</label>
                            <input id="location" name="location" type="text" placeholder="Austin, TX">
                        </div>
                        <div>
                            <label for="phone">Phone</label>
                            <input id="phone" name="phone" type="text" placeholder="+1 512 555 1234">
                        </div>
                    </div>
                    <div class="row">
                        <label for="country">Country (2-letter code)</label>
                        <input id="country" name="country" type="text" placeholder="us">
                    </div>
                    <div class="row inline">
                        <div>
                            <label for="website">Website</label>
                            <input id="website" name="website" type="url" placeholder="https://example.com">
                        </div>
                        <div>
                            <label for="industry">Industry</label>
                            <input id="industry" name="industry" type="text" placeholder="Home Services">
                        </div>
                    </div>
                    <label class="toggle">
                        <input id="skip_places" name="skip_places" type="checkbox">
                        Skip Google Places if no match
                    </label>
                    <div class="actions">
                        <button type="submit">Run Scan</button>
                        <button type="button" class="secondary" id="reset">Reset</button>
                    </div>
                </form>
            </section>

            <section class="panel">
                <details open>
                    <summary>Results</summary>
                    <div class="status" id="status">No request yet.</div>
                    <div id="candidates" class="candidates"></div>
                    <pre id="output">{}</pre>
                </details>
            </section>
        </div>
    </div>

    <script>
        const form = document.getElementById("scan-form");
        const output = document.getElementById("output");
        const statusEl = document.getElementById("status");
        const candidatesEl = document.getElementById("candidates");
        const resetButton = document.getElementById("reset");

        let lastBasePayload = null;

        function buildPayload(extra = {}) {
            const data = new FormData(form);
            const payload = {
                business_name: data.get("business_name")?.trim(),
                location: data.get("location")?.trim(),
                phone: data.get("phone")?.trim(),
                website: data.get("website")?.trim(),
                industry: data.get("industry")?.trim(),
                country: data.get("country")?.trim(),
                skip_places: data.get("skip_places") === "on"
            };

            Object.keys(payload).forEach((key) => {
                if (payload[key] === "" || payload[key] === null || payload[key] === undefined) {
                    delete payload[key];
                }
            });

            return { ...payload, ...extra };
        }

        function renderOutput(data) {
            output.textContent = JSON.stringify(data, null, 2);
            statusEl.textContent = `Status: ${data.status || "unknown"}`;
        }

        function renderCandidates(candidates) {
            candidatesEl.innerHTML = "";
            if (!candidates || candidates.length === 0) {
                return;
            }

            candidates.forEach((candidate) => {
                const card = document.createElement("div");
                card.className = "candidate";
                const name = candidate.name || "Unknown business";
                const address = candidate.address || "No address";
                const rating = candidate.rating ? `Rating ${candidate.rating}` : "No rating";
                const count = candidate.review_count ? `(${candidate.review_count} reviews)` : "";

                card.innerHTML = `
                    <h3>${name}</h3>
                    <small>${address}</small>
                    <small>${rating} ${count}</small>
                `;

                const button = document.createElement("button");
                button.type = "button";
                button.textContent = "Select";
                button.addEventListener("click", () => {
                    if (!candidate.place_id || !lastBasePayload) {
                        return;
                    }
                    runScan({ place_id: candidate.place_id });
                });

                card.appendChild(button);
                candidatesEl.appendChild(card);
            });

            const skipButton = document.createElement("button");
            skipButton.type = "button";
            skipButton.className = "secondary";
            skipButton.textContent = "None of these (skip Places)";
            skipButton.addEventListener("click", () => {
                runScan({ skip_places: true });
            });
            candidatesEl.appendChild(skipButton);
        }

        async function runScan(extra = {}) {
            const payload = lastBasePayload ? { ...lastBasePayload, ...extra } : buildPayload(extra);
            lastBasePayload = payload;
            candidatesEl.innerHTML = "";

            statusEl.textContent = "Running scan...";
            output.textContent = "{}";

            try {
                const response = await fetch("/api/reputation/scan", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                renderOutput(data);

                if (data.status === "selection_required") {
                    renderCandidates(data.candidates || []);
                }
            } catch (error) {
                statusEl.textContent = "Request failed.";
                output.textContent = JSON.stringify({ error: error.message }, null, 2);
            }
        }

        form.addEventListener("submit", (event) => {
            event.preventDefault();
            lastBasePayload = buildPayload();
            runScan();
        });

        resetButton.addEventListener("click", () => {
            form.reset();
            lastBasePayload = null;
            candidatesEl.innerHTML = "";
            output.textContent = "{}";
            statusEl.textContent = "No request yet.";
        });
    </script>
</body>
</html>
