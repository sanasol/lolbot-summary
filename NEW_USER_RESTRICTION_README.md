# New User Restriction Anti-Spam System

## Overview

This system prevents spam by restricting new users from sending messages immediately after joining. New users must either:
1. **Solve a simple math captcha** (e.g., "5 + 7 = ?"), OR
2. **Wait 10 minutes** before they can send messages

## Features

### 1. **Automatic Detection**
- Detects when new users join the chat
- Automatically sends a captcha challenge

### 2. **Captcha Challenge**
- Simple math problem (addition of two numbers from 1-10)
- Users have 2 minutes to answer
- If expired, a new captcha is automatically sent

### 3. **Message Deletion**
- Deletes messages from restricted users
- Deletes captcha answers (correct or incorrect)
- Deletes warning messages after 30 seconds
- Deletes expired captcha messages automatically

### 4. **User-Friendly Warnings**
- Shows remaining wait time to users
- Clear instructions on how to verify
- Success message when captcha is solved correctly

### 5. **Automatic Cleanup**
- Auto-verifies users after 10 minutes
- Cleans up old user records after 24 hours
- Processes message deletions periodically

## Configuration

### Enable/Disable Per Chat

Use the settings service to enable this feature for specific chats:

```php
$settingsService->setSetting($chatId, 'new_user_restriction_enabled', true);
```

### Customizable Constants

In `NewUserRestrictionService.php`:

```php
private const WAIT_TIME_SECONDS = 600; // 10 minutes
private const CAPTCHA_TIMEOUT_SECONDS = 120; // 2 minutes to answer
private const AUTO_DELETE_DELAY_SECONDS = 30; // Delete messages after 30 seconds
```

## How It Works

### Flow Diagram

```
New User Joins
    ↓
Send Captcha (e.g., "5 + 7 = ?")
    ↓
User Attempts to Send Message
    ↓
Is it a captcha answer? ──Yes──→ Correct? ──Yes──→ ✅ Verified
    │                                │
    No                               No
    ↓                                ↓
Wait time passed? ──Yes──→ ✅ Auto-verified    Delete & show error
    │
    No
    ↓
Delete message & show warning
```

### State Management

The service maintains state in `new_user_restrictions.json`:

```json
{
  "newUsers": {
    "chat_id": {
      "user_id": {
        "joined_at": 1234567890,
        "verified": false,
        "username": "newuser",
        "captcha": {
          "num1": 5,
          "num2": 7,
          "answer": 12,
          "messages": [12345, 12346],
          "expires_at": 1234567990
        }
      }
    }
  },
  "messagesToDelete": [
    {
      "chat_id": -1001234567890,
      "message_id": 12347,
      "delete_at": 1234568000
    }
  ]
}
```

## Integration

### Services Used

1. **NewUserRestrictionService** - Main service handling restrictions
2. **WebhookProcessor** - Integrates checks into message processing
3. **PeriodicTaskRunner** - Handles cleanup and scheduled deletions
4. **SettingsService** - Per-chat configuration

### Key Methods

#### `handleNewMember(int $chatId, int $userId, string $username)`
Called when a new member joins the chat.

#### `checkUserAllowed(int $chatId, int $userId, int $messageId)`
Returns whether a user is allowed to send messages.

#### `handlePotentialCaptchaAnswer(int $chatId, int $userId, string $messageText, int $messageId)`
Checks if a message is a captcha answer and validates it.

#### `processScheduledDeletions()`
Deletes messages that are scheduled for deletion.

#### `cleanupOldUsers()`
Removes old user records (24+ hours old).

## Example Messages

### Welcome Message (Captcha)
```
👋 Welcome @newuser!

To prevent spam, please solve this simple math problem:

5 + 7 = ?

Send just the number as your answer, or wait 10 minutes to send messages freely.
```

### Incorrect Answer
```
❌ Incorrect answer. Please try again or wait 9 more minutes to send messages.
```

### Success Message
```
✅ Welcome! You can now send messages.
```

### Restriction Warning
```
⚠️ You need to complete the captcha or wait 8 more minute(s) before you can send messages.
```

## Testing

### Test Scenario 1: Correct Captcha Answer
1. New user joins
2. Bot sends "5 + 7 = ?"
3. User types "12"
4. ✅ User is verified immediately
5. All captcha messages are deleted

### Test Scenario 2: Wait Time
1. New user joins
2. Bot sends captcha
3. User waits 10 minutes without answering
4. User sends a message
5. ✅ User is auto-verified
6. Message is allowed

### Test Scenario 3: Wrong Answer
1. New user joins
2. Bot sends "5 + 7 = ?"
3. User types "10"
4. ❌ Message deleted, warning sent
5. User must try again

### Test Scenario 4: Spam Attempt
1. New user joins
2. User tries to send spam message
3. Message is immediately deleted
4. Warning is shown
5. Chat stays clean

## Enabling the Feature

Add this to your bot command handler or settings:

```php
// Enable for a specific chat
$bot->getSettingsService()->setSetting($chatId, 'new_user_restriction_enabled', true);

// Disable for a specific chat
$bot->getSettingsService()->setSetting($chatId, 'new_user_restriction_enabled', false);
```

## File Locations

- **Service**: `src/Services/NewUserRestrictionService.php`
- **Integration**: `src/Services/WebhookProcessor.php` (lines 89-179)
- **Cleanup**: `src/Services/PeriodicTaskRunner.php` (lines 209-222)
- **State File**: `data/new_user_restrictions.json` (auto-created)

## Benefits

✅ **Prevents spam** from newly joined accounts
✅ **User-friendly** - simple captcha or wait option
✅ **Clean chat** - auto-deletes all restriction-related messages
✅ **Configurable** - enable/disable per chat
✅ **No manual intervention** - fully automatic
✅ **Persistent** - survives bot restarts

## Future Enhancements

- Custom captcha difficulty levels
- Image-based captchas
- Admin bypass options
- Custom wait times per chat
- Statistics tracking
