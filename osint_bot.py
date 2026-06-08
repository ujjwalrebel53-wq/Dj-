#!/usr/bin/env python3
"""
Advanced OSINT Telegram Bot
Bhai isko run karne ke liye:
  pip install python-telegram-bot httpx dnspython phonenumbers
  export TELEGRAM_TOKEN="your_bot_token"
  python osint_bot.py

Commands:
  /start          - Help
  /phone <num>    - Phone info
  /email <email>  - Email DNS + format check
  /username <u>   - Username search (public sites)
  /ip <ip>        - IP geolocation
  /domain <dom>   - DNS + WHOIS style info
  /url <url>      - URL headers + redirect chain
"""

from __future__ import annotations

import asyncio
import json
import os
import re
import socket
from datetime import datetime
from typing import Any
from urllib.parse import urlparse

import httpx
import phonenumbers
from dns import resolver
from phonenumbers import carrier, geocoder, timezone
from telegram import Update
from telegram.ext import Application, CommandHandler, ContextTypes, MessageHandler, filters

TOKEN = os.getenv("TELEGRAM_TOKEN", "YOUR_TELEGRAM_BOT_TOKEN")
HTTP_TIMEOUT = 15.0

USER_SITES = {
    "GitHub": "https://github.com/{}",
    "Twitter/X": "https://x.com/{}",
    "Instagram": "https://www.instagram.com/{}/",
    "Reddit": "https://www.reddit.com/user/{}",
    "TikTok": "https://www.tiktok.com/@{}",
    "Pinterest": "https://www.pinterest.com/{}/",
    "Medium": "https://medium.com/@{}",
    "Dev.to": "https://dev.to/{}",
    "HackerNews": "https://news.ycombinator.com/user?id={}",
    "Telegram": "https://t.me/{}",
}


def hinglish(text: str) -> str:
    return text


async def fetch_json(client: httpx.AsyncClient, url: str) -> dict[str, Any]:
    try:
        r = await client.get(url, follow_redirects=True)
        r.raise_for_status()
        return r.json()
    except Exception as e:
        return {"error": str(e)}


async def check_url_exists(client: httpx.AsyncClient, url: str) -> tuple[bool, int]:
    try:
        r = await client.head(url, follow_redirects=True, timeout=HTTP_TIMEOUT)
        return r.status_code < 400, r.status_code
    except Exception:
        try:
            r = await client.get(url, follow_redirects=True, timeout=HTTP_TIMEOUT)
            return r.status_code < 400, r.status_code
        except Exception:
            return False, 0


async def cmd_start(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    msg = hinglish(
        "🔍 *OSINT Bot* — swagat hai bhai!\n\n"
        "Commands:\n"
        "• `/phone +9198XXXXXXXX` — phone info\n"
        "• `/email test@gmail.com` — email check\n"
        "• `/username rebeluser` — username hunt\n"
        "• `/ip 8.8.8.8` — IP location\n"
        "• `/domain google.com` — DNS records\n"
        "• `/url https://example.com` — URL analysis\n\n"
        "_Sirf public OSINT — illegal cheez mat karna!_"
    )
    await update.message.reply_text(msg, parse_mode="Markdown")


async def cmd_phone(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not context.args:
        await update.message.reply_text("Usage: /phone +919876543210")
        return

    number = "".join(context.args)
    try:
        parsed = phonenumbers.parse(number, None)
        if not phonenumbers.is_valid_number(parsed):
            await update.message.reply_text("Bhai number valid nahi lag raha.")
            return

        info = {
            "number": phonenumbers.format_number(parsed, phonenumbers.PhoneNumberFormat.INTERNATIONAL),
            "country": geocoder.description_for_number(parsed, "en"),
            "carrier": carrier.name_for_number(parsed, "en") or "Unknown",
            "timezone": ", ".join(timezone.time_zones_for_number(parsed)),
            "type": phonenumbers.number_type(parsed),
        }
        text = "📱 *Phone OSINT*\n\n" + "\n".join(f"• *{k}:* `{v}`" for k, v in info.items())
        await update.message.reply_text(text, parse_mode="Markdown")
    except Exception as e:
        await update.message.reply_text(f"Error: {e}")


async def cmd_email(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not context.args:
        await update.message.reply_text("Usage: /email someone@gmail.com")
        return

    email = context.args[0].strip().lower()
    if not re.match(r"^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$", email):
        await update.message.reply_text("Email format galat hai bhai.")
        return

    domain = email.split("@")[1]
    lines = [f"📧 *Email OSINT* — `{email}`\n"]

    try:
        mx = resolver.resolve(domain, "MX")
        lines.append("*MX Records:*")
        for r in mx:
            lines.append(f"  • {r.exchange} (prio {r.preference})")
    except Exception as e:
        lines.append(f"MX lookup fail: {e}")

    try:
        a = resolver.resolve(domain, "A")
        lines.append("*A Records:* " + ", ".join(str(x) for x in a))
    except Exception:
        pass

    async with httpx.AsyncClient(timeout=HTTP_TIMEOUT) as client:
        data = await fetch_json(client, f"https://dns.google/resolve?name={domain}&type=TXT")
        answers = data.get("Answer", [])
        if answers:
            lines.append("*TXT (sample):*")
            for ans in answers[:5]:
                lines.append(f"  • {ans.get('data', '')[:120]}")

    lines.append("\n_Tip: HaveIBeenPwned API key se breach check add kar sakte ho._")
    await update.message.reply_text("\n".join(lines), parse_mode="Markdown")


async def cmd_username(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not context.args:
        await update.message.reply_text("Usage: /username rebeluser")
        return

    username = re.sub(r"[^a-zA-Z0-9_.-]", "", context.args[0])[:32]
    lines = [f"👤 *Username Hunt* — `{username}`\n"]

    async with httpx.AsyncClient(timeout=HTTP_TIMEOUT, headers={"User-Agent": "OSINTBot/1.0"}) as client:
        tasks = []
        names = []
        for name, tpl in USER_SITES.items():
            url = tpl.format(username)
            names.append((name, url))
            tasks.append(check_url_exists(client, url))

        results = await asyncio.gather(*tasks)
        found = 0
        for (name, url), (ok, status) in zip(names, results):
            icon = "✅" if ok else "❌"
            if ok:
                found += 1
            lines.append(f"{icon} *{name}* — [{url}]({url}) ({status})")

    lines.append(f"\n*Found on {found}/{len(USER_SITES)} platforms*")
    await update.message.reply_text("\n".join(lines), parse_mode="Markdown", disable_web_page_preview=True)


async def cmd_ip(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not context.args:
        await update.message.reply_text("Usage: /ip 8.8.8.8")
        return

    ip = context.args[0].strip()
    if not re.match(r"^(?:\d{1,3}\.){3}\d{1,3}$", ip):
        await update.message.reply_text("Valid IPv4 do bhai.")
        return

    async with httpx.AsyncClient(timeout=HTTP_TIMEOUT) as client:
        data = await fetch_json(client, f"http://ip-api.com/json/{ip}?fields=status,message,country,regionName,city,zip,lat,lon,timezone,isp,org,as,query")

    if data.get("status") != "success":
        await update.message.reply_text(f"Lookup fail: {data.get('message', 'unknown')}")
        return

    fields = ["query", "country", "regionName", "city", "zip", "lat", "lon", "timezone", "isp", "org", "as"]
    lines = ["🌐 *IP Geolocation*\n"] + [f"• *{f}:* `{data.get(f, '-')}`" for f in fields]
    lines.append(f"\n🗺 Maps: https://www.google.com/maps?q={data.get('lat')},{data.get('lon')}")
    await update.message.reply_text("\n".join(lines), parse_mode="Markdown")


async def cmd_domain(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not context.args:
        await update.message.reply_text("Usage: /domain google.com")
        return

    domain = context.args[0].strip().lower()
    lines = [f"🌍 *Domain OSINT* — `{domain}`\n"]

    for rtype in ("A", "AAAA", "MX", "NS", "TXT"):
        try:
            ans = resolver.resolve(domain, rtype)
            lines.append(f"*{rtype}:*")
            for r in ans:
                lines.append(f"  • {r}")
        except Exception:
            pass

    try:
        ip = socket.gethostbyname(domain)
        lines.append(f"\n*Resolved IP:* `{ip}`")
    except Exception as e:
        lines.append(f"Resolve error: {e}")

    await update.message.reply_text("\n".join(lines)[:4000], parse_mode="Markdown")


async def cmd_url(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not context.args:
        await update.message.reply_text("Usage: /url https://example.com")
        return

    url = context.args[0].strip()
    if not url.startswith(("http://", "https://")):
        url = "https://" + url

    async with httpx.AsyncClient(timeout=HTTP_TIMEOUT, follow_redirects=True) as client:
        try:
            r = await client.get(url)
            lines = [
                f"🔗 *URL Analysis*\n",
                f"• *Final URL:* `{str(r.url)}`",
                f"• *Status:* `{r.status_code}`",
                f"• *Server:* `{r.headers.get('server', '-')}`",
                f"• *Content-Type:* `{r.headers.get('content-type', '-')}`",
                f"• *Content-Length:* `{len(r.content)} bytes`",
            ]
            title = re.search(r"<title[^>]*>([^<]+)</title>", r.text, re.I)
            if title:
                lines.append(f"• *Title:* {title.group(1).strip()[:100]}")
            await update.message.reply_text("\n".join(lines), parse_mode="Markdown")
        except Exception as e:
            await update.message.reply_text(f"URL fetch fail: {e}")


async def fallback(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    await update.message.reply_text(
        hinglish("Bhai command samajh nahi aaya. /start dabao help ke liye.")
    )


def main() -> None:
    if TOKEN == "YOUR_TELEGRAM_BOT_TOKEN":
        raise SystemExit("Pehle TELEGRAM_TOKEN set karo: export TELEGRAM_TOKEN=...")

    app = Application.builder().token(TOKEN).build()
    app.add_handler(CommandHandler("start", cmd_start))
    app.add_handler(CommandHandler("help", cmd_start))
    app.add_handler(CommandHandler("phone", cmd_phone))
    app.add_handler(CommandHandler("email", cmd_email))
    app.add_handler(CommandHandler("username", cmd_username))
    app.add_handler(CommandHandler("ip", cmd_ip))
    app.add_handler(CommandHandler("domain", cmd_domain))
    app.add_handler(CommandHandler("url", cmd_url))
    app.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, fallback))

    print("OSINT Bot chal raha hai...")
    app.run_polling(allowed_updates=Update.ALL_TYPES)


if __name__ == "__main__":
    main()
