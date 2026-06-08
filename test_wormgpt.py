#!/usr/bin/env python3
"""Test WormGPT API — same logic as cursor.php wormChat()"""

import json
import re
import urllib.parse
import urllib.request

WORM_API = "https://wormgpt.freeapihub.workers.dev/chat"

PROMPT = """STRICT RULES — MUST FOLLOW:
1. ALWAYS reply in Hinglish (Hindi + English mix).
2. POORA COMPLETE code likho — kabhi "..." ya placeholder mat chhod.
3. Functions, imports, main — sab include karo.

Tu coding AI hai. File: osint_bot.py

USER: advanced osint telegram bot ka POORA complete python code likho. phone, email, username, ip lookup. poora code ek file mein."""


def worm_chat(prompt: str, max_parts: int = 8) -> str:
    full = ""
    part_prompt = prompt

    for i in range(max_parts):
        url = WORM_API + "?q=" + urllib.parse.quote(part_prompt)
        req = urllib.request.Request(url, headers={
            "Accept": "application/json",
            "User-Agent": "Mozilla/5.0 (CursorPHP Test)",
        })
        try:
            with urllib.request.urlopen(req, timeout=120) as resp:
                data = json.loads(resp.read().decode())
        except Exception as e:
            print(f"→ API ERROR on part {i+1}: {e}")
            break

        chunk = data.get("reply", "")
        if not chunk:
            break

        full += ("\n" if full else "") + chunk
        fences = full.count("```")
        chunk_len = len(chunk)
        closed = fences % 2 == 0

        print(f"\n--- PART {i+1} | chunk={chunk_len} chars | total={len(full)} | ``` pairs closed={closed} ---")
        print(chunk[:400] + ("..." if len(chunk) > 400 else ""))

        if chunk_len < 550 and closed:
            print("→ STOP: short + closed blocks")
            break
        if i > 0 and chunk_len < 400 and closed:
            print("→ STOP: continuation done")
            break

        if i < max_parts - 1:
            tail = chunk[-400:]
            part_prompt = f"""Continue EXACTLY where you stopped. DO NOT repeat. Hinglish.
POORA code complete karo. Last part:
---
{tail}
---
Continue:"""

    return full


def analyze(reply: str) -> dict:
    code_blocks = re.findall(r"```[\w]*\n([\s\S]*?)```", reply)
    return {
        "total_chars": len(reply),
        "code_blocks": len(code_blocks),
        "total_code_chars": sum(len(c) for c in code_blocks),
        "has_import": "import " in reply,
        "has_def_or_class": "def " in reply or "class " in reply,
        "has_placeholder": "..." in reply or "YOUR_" in reply or "placeholder" in reply.lower(),
        "hinglish_hints": bool(re.search(r"\b(bhai|yar|karo|likho|ye|hai|ke liye)\b", reply, re.I)),
        "largest_block_lines": max((c.count("\n") for c in code_blocks), default=0),
    }


if __name__ == "__main__":
    print("=" * 60)
    print("WORMGPT TEST — OSINT bot code request")
    print("=" * 60)

    reply = worm_chat(PROMPT, 8)
    stats = analyze(reply)

    print("\n" + "=" * 60)
    print("RESULT SUMMARY")
    print("=" * 60)
    for k, v in stats.items():
        print(f"  {k}: {v}")

    print("\n--- FULL REPLY (last 1500 chars) ---")
    print(reply[-1500:] if len(reply) > 1500 else reply)

    with open("/workspace/wormgpt_test_output.txt", "w") as f:
        f.write(reply)
    print(f"\nFull output saved: wormgpt_test_output.txt ({len(reply)} chars)")
