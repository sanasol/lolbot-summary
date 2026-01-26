# Lolbot Summary - Telegram Bot

## Overview

Telegram bot for chat summaries and ClickHouse data analysis. Deployed at `/home/summary` on 195 server.

## Domain & Webhook

| Domain | Purpose | Routing |
|--------|---------|---------|
| `sum.statbate.com` | Telegram webhook endpoint | Traefik → summary-bot container |

**IMPORTANT:** Domain must be configured in Traefik routes (`statbate-docker/traefik/config/routes.yaml`):

```yaml
# In http.routers section:
sum-statbate:
  rule: "Host(`sum.statbate.com`)"
  entryPoints:
    - websecure
  service: summary-bot
  tls:
    certResolver: letsencrypt

# In http.services section:
summary-bot:
  loadBalancer:
    servers:
      - url: "http://summary-bot:8080"
```

## Deployment

```bash
# Server: 195.3.220.43
ssh root@195.3.220.43
cd /home/summary

# Restart bot
docker compose restart queue-worker

# View logs
docker logs summary-queue-worker-1 --tail 100

# Check webhook logs
tail -f /home/summary/data/webhook_$(date +%Y-%m-%d).log
```

## Key Features

### ClickHouse Agent (`/mcp` command)

AI-powered data analysis using NeuronAI framework with:
- **Statbate API integration** - Preferred for member/model queries
- **Direct ClickHouse queries** - For complex custom queries
- **Persistent chat history** - Per group/thread context (30k token window)
- **Dynamic chat actions** - Shows typing/searching status during queries

### API Integration

Internal API token: `apollo-secret-api-user-76543132456`

Available endpoints:
- `/members/{site}/{name}/info` - Member stats
- `/members/{site}/{name}/tips` - Tip history
- `/members/{site}/{name}/top-models` - Top tipped models
- `/model/{site}/{name}/info` - Model stats
- `/model/{site}/{name}/tips` - Model tip history
- `/model/{site}/{name}/members` - Top tippers

### Chat Actions (Status Indicators)

Bot shows different Telegram actions during processing:
- `find_location` - Searching database
- `upload_document` - Processing results
- `typing` - Thinking
- `record_video` - Heavy computation
- `choose_sticker` - Almost done

Actions support threaded groups via `message_thread_id`.

### Log Rotation

Automatic cleanup runs hourly:
- Deletes files older than 7 days
- Cleans: `*.log`, `chat_history/*.chat`
- Logs cleanup summary to webhook log

## File Structure

```
/home/summary/
├── data/
│   ├── chat_history/     # Persistent MCP chat history
│   ├── webhook_*.log     # Daily webhook logs
│   ├── error_*.log       # Daily error logs
│   └── *_messages.json   # Chat message storage
├── src/
│   └── Services/
│       ├── ClickhouseAgent.php      # AI agent with tools
│       ├── ChatActionObserver.php   # Status indicator observer
│       ├── MCPResponseGenerator.php # MCP response handling
│       ├── PeriodicTaskRunner.php   # Background tasks
│       └── TelegramSender.php       # Telegram API wrapper
└── config/
    └── config.php        # Bot configuration
```

## Configuration

Key config values in `config/config.php`:
- `telegram_bot_token` - Bot API token
- `log_path` - Data directory path
- `clickhouse` - ClickHouse connection settings
- `openrouter_key` - AI model API key
- `openrouter_tool_model` - Model for tool calls

## Troubleshooting

### Bot not responding
1. Check Traefik routes for `sum.statbate.com`
2. Verify container is running: `docker ps | grep summary`
3. Check webhook logs for errors

### Chat actions not showing in threads
- Ensure `message_thread_id` is passed through the call chain
- Check TelegramSender::sendChatAction includes thread parameter

### Tool call limit errors
- ClickHouse tools: max 10 attempts
- API tools: max 1000 attempts (effectively unlimited)

## Recent Changes (2026-01-26)

1. **Statbate API Integration** - Added `call_statbate_api` tool for stable queries
2. **Dynamic Chat Actions** - Shows status during long queries
3. **Thread Support** - Chat actions work in forum topics
4. **Persistent History** - FileChatHistory per group/thread
5. **Log Rotation** - Automatic 7-day cleanup
6. **Tool Limits** - Configurable per-tool max attempts
