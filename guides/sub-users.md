# Multi-User Setup Guide

This guide provides a step-by-step overview of how to configure and use the multi-user (sub-user) functionality. While
the tool is primarily designed for single-user environments, multi-user support is available and works well in most
scenarios. However, due to the foundational single-user architecture, occasional limitations may arise.

---

## Overview

Multi-user support allows you to manage and configure separate configurations for multiple users, such as family members
or users on a shared server. Sub-users inherit the base setup from the primary user and can be customized as needed.

---

## Single Backend Mode vs. Multi-Backend Mode

WatchState supports two modes for sub-user creation:

### Multi-Backend Mode (Default)

This is the standard mode used when you have **2 or more backends** configured. The system will:

* Attempt to match users across different backends based on usernames.
* Require user grouping/mapping to associate the same person across multiple services.
* Create unified configurations that sync play states between all matched backends.

### Single Backend Mode

This mode is available when you have **exactly 1 backend** configured. In this mode:

* **No user matching required** - Each user from the backend gets their own configuration.
* **Simplified setup** - No need to create user groups or mappings.
* **Perfect for dedicated servers** - Ideal if you only use one media server (e.g., only Plex, only Jellyfin).
* **Ideal for backing up user play states** - The use case for this is to backup user play state and watch progress,
  allowing you to restore individual user data without affecting others.
* **PIN support** - You can still set PINs for protected users via the mapper.

To enable single backend mode:

1. Navigate to **<!--i:fa-tools--> Tools > <!--i:fa-users--> Sub Users**.
2. If you have exactly 1 backend configured, you'll see an **"Allow single backend users"** checkbox.
3. Check this option to enable single backend mode.
4. All users from that backend (except the main user) will be created as individual sub-users.

> [!NOTE]
> Single backend mode still respects PIN settings for protected Plex users. You can set PINs by clicking the lock icon
> next to each user.

---

## Step-by-Step Guide to Multi-User Setup

### 1. Add Your Backends

Begin by configuring your media server backends as you normally would for a single user.

* Ensure that **all backends used by the main user** are correctly added and operational.
* These backends will serve as the foundation for sub-user configurations.

---

### 2. Configure the Plex Backend (If Applicable)

If you're using Plex:

* You **must** provide an **Admin-level `X-Plex-Token`** to access the user list required for sub-user grouping.
* To verify your token:

    * Navigate to **<!--i:fa-tools--> Tools > <!--i:fa-key--> Plex Token**.
    * Review the message:
        * If it shows **Success**, your token has sufficient privileges.
        * If it shows an **Error**, your token likely has restricted permissions. Replace it with a valid admin token.

---

### 3. Configure Jellyfin or Emby Backends (If Applicable)

If you're using Jellyfin or Emby:

* You should use an **API Key**, which can be generated from your server settings.
* To create an API key:

    1. Go to **Dashboard > Advanced > API Keys**.
    2. Generate a new key.
    3. Add the key to the corresponding backend configuration.

---

### 4. Access the Sub-Users Management Tool

After verifying all backends are properly configured:

1. Navigate to **<!--i:fa-tools--> Tools > <!--i:fa-users--> Sub Users**.
2. **If you have multiple backends:**
    * The system will attempt to **automatically group users** based on matching names across the backends.
    * **Manual adjustment may be necessary**:
        * Use drag-and-drop functionality to reassign or organize users into the appropriate groups.
        * This is helpful when naming conventions differ across services.
3. **If you have a single backend:**
    * Enable the **"Allow single backend users"** checkbox.
    * All users will be listed in the unmatched section and will be created individually.
    * No grouping or matching is required.

---

### 5. Handle Protected Users (If Applicable)

If you have Plex users with managed user accounts (protected users):

* **These users require a PIN** to be set before sub-user creation can proceed.
* Click the **lock icon** (<!--i:fa-lock-open-->) next to each protected user to set their 4-digit PIN.
* The PIN will be stored in the mapper and used when generating access tokens.
* In **single backend mode**, you can set PINs for unmatched users directly in the unmatched users section.

> [!IMPORTANT]
> You cannot proceed with sub-user creation if any protected users are missing PINs. The system will display a warning
> message and disable the creation buttons until all PINs are set.

---

### 6. Create Sub-Users

Once users are properly grouped (in multi-backend mode) or configured (in single backend mode):

* Click the `Create Sub-users` button.
* The system will generate configurations for each sub-user, based on the main user's settings.
* In **single backend mode**, each user gets their own independent configuration.

---

### 7. Configure Settings for Sub-Users

With user mapping in place:

* Select `Change User` in the top right of the WebUI.
* `Browse as` a sub-user.
* Configure settings for each sub-user.

---

## Maintaining Sub-User Configurations

If you make changes to the main user configuration (e.g., change backend URLs, tokens, etc.), you must also update
sub-user configurations.

You can do this in one of two ways:

1. **Manually Update Each Sub-User**
   Adjust the configurations for each sub-user individually.

2. **Click `Update Sub-users`**
   This will:

    * Attempt to propagate changes from the main configuration.
    * **Create new sub-users** for any users that do not yet exist in the system.

> [!IMPORTANT]
> **Caution:** The `Update Sub-users` feature can result in the creation of new sub-users. Use it only when you are
> certain of the impact.

---

## Naming Conventions

The system enforces a strict naming convention for both **backend names** and **usernames**.

### Allowed Format

```
^[a-z_0-9]+$
```

### Rules

* **Allowed:** lowercase letters, digits, underscores (`_`)
* **Not Allowed:** uppercase letters, spaces, or special characters

### Automatic Normalization

* If a username does not conform to this format, the system will automatically normalize it.
* If a username is composed entirely of digits, it will be prefixed with `user_` (e.g., `12345` → `user_12345`).

---

## Conclusion

Multi-user support enables effective user management within a single instance, even though the system was originally
built for single-user operation. By following the setup instructions and observing naming conventions, you can
confidently configure and manage sub-users with minimal issues. Be mindful of changes to the main configuration and
always validate backend permissions before generating or updating sub-users. It's helpful to read the extra information
provides in the sub-users page.
