# Update Monitor for Joomla

Update Monitor for Joomla automatically checks for available updates of **Joomla Core**, **installed extensions** and **language packs**. When new updates are detected it can notify one or more recipients by email.

Security-related extension updates are identified and highlighted when the extension developer provides the corresponding security metadata. Severity levels (Low, Medium, High, Critical) are shown in the dashboard, the update list and in notification emails.

Every detected update is stored in a history, so you can see when it was first detected, when it was resolved and when notifications were sent.

> **Update Monitor for Joomla does not install updates.** It helps administrators keep an overview, spot security-related updates and make sure important updates are not overlooked.

---

## Features

- Automatic update checks using **Joomla Scheduled Tasks**
- Email notifications for newly available updates
- Additional notification when the **security severity of an existing update increases**
- **Dashboard** with update overview, a dedicated Joomla Core card and recent activity
- **Update history** with detection and resolution tracking
- **Ignore** individual updates
- **Activity log** for update checks, notifications, reminders, reports and cleanups (can be cleared manually)
- **Update server overview**
- Configurable **reminders** for pending updates
- **Weekly and monthly status reports** by email
- Automatic **cleanup** with configurable retention periods
- Protection against duplicate notifications and overlapping checks
- Language files: English (en-GB) and German (de-DE)

## Requirements

- Joomla 5 or Joomla 6
- PHP 8.1 or newer
- MySQL or MariaDB (the install SQL is written for these; PostgreSQL is not supported)
- Working Joomla mail configuration (System → Global Configuration → Server → Mail)

## Installation

1. Download `pkg_updatemonitor.zip`.
2. In the Joomla administrator go to **System → Install → Extensions** and upload the ZIP.
3. The package installs two extensions:
   - **Component** `com_updatemonitor` (dashboard, history, log, options)
   - **Plugin** `Task - Update Monitor for Joomla` (group `task`, enabled automatically)
4. Create the scheduled task (see below).

Updating works the same way: install the new package over the existing one. Data is kept.

## Setup: scheduled task

The plugin provides a task, but it is **not created automatically**.

1. Go to **System → Scheduled Tasks → New**.
2. Select the task type **Update Monitor for Joomla: check for updates**.
3. Choose an execution interval (for example once per day).
4. Save and enable the task.

### How the task gets triggered

The plugin itself does not react to page views. Joomla's scheduler runs the task when it is due. The scheduler can be triggered in three ways:

| Method | Description |
|---|---|
| **Lazy Scheduler** | Due tasks are executed in the background while pages are being loaded. Needs visitors; the check may slow down a request. Configure under System → Scheduled Tasks → Options. |
| **Webcron** | An external service or cron job calls a URL that Joomla shows in the scheduler options (webcron must be enabled and a key set). |
| **CLI cron** | The server runs `php cli/joomla.php scheduler:run` regularly, for example every 5 minutes. |

For production sites, **Webcron or CLI cron is recommended**. If the task runs via CLI, set the **Site URL** in the options of Update Monitor for Joomla, otherwise the links in emails may point to a wrong address.

You can also run a check manually at any time with **Run check now** on the dashboard. In addition, **opening the component** from the menu starts a check automatically. That check never sends emails and can be switched off in the options.

## How a check works

1. A lock is acquired so that two checks never run at the same time (a lock older than 30 minutes is considered stale).
2. If **Search for updates before each check** is enabled (default), the same search as the **Find Updates** button of the Joomla Installer is executed. All enabled update servers are queried without cache. The minimum stability setting of `com_installer` is respected. If disabled, only the update list Joomla has already cached is used.
3. The update list of Joomla (`#__updates`) is read and compared with the monitor's history.
4. For each update, the changelog is fetched (optional) to detect security information.
5. New updates are stored, resolved updates are closed.
6. Emails are sent if enabled for the kind of check that was started (see *Options → Notifications*).
7. Scheduled runs additionally send reminders and reports (if emails are enabled for scheduled checks) and run the cleanup. Manual checks and the automatic check on opening the component never send reminders or reports and never run the cleanup.

| Check started by | Sends mails about new updates | Reminders, reports, cleanup |
|---|---|---|
| Scheduled Tasks plugin | if *Send emails after a scheduled check* is enabled | yes (reminders/reports only if mails are enabled) |
| *Run check now* button | if *Send emails after a manual check* is enabled | no |
| Opening the component | never | no |

Updates found by a check that sends no mail are still stored. The next check that sends mail reports them.

## Dashboard and views

Open the component via **Components → Update Monitor for Joomla**.

| Tab | Content |
|---|---|
| **Dashboard** | Joomla Core card (or "up to date" notice), counters (pending, security, ignored, resolved in the last 30 days), severity breakdown, top pending extension updates, recent activity. Buttons: *Run check now*, *Send test mail*. |
| **Updates** | All pending updates, sorted with Joomla Core first, then by severity. Switch between *Pending* and *Ignored*. Each entry can be ignored or un-ignored. |
| **History** | All detected updates with status, first detected, resolved, and last notification (number of notifications in brackets). |
| **Activity Log** | Checks, new/resolved updates, notifications, reminders, reports, cleanups, errors. *Clear activity log* removes all entries. |
| **Update Servers** | Overview of Joomla update sites: name, extension, type, location, enabled, last checked. |

If no check has been run yet, the dashboard says so instead of reporting "no updates".

After a check, a message shows the result: **green** if no update is available, **yellow** if updates are available, **red** if the check had errors. When extension updates are pending, the dashboard offers a button that opens Joomla's own extension update page (`System → Update → Extensions`).

## Notifications

### Recipients

- All active **Super Users** with **Receive System Emails = Yes** (can be switched off)
- Optional additional addresses (separated by comma, semicolon or new line)

Addresses are de-duplicated. Use **Send test mail** on the dashboard to verify delivery.

### When mails are sent

- **New update detected** (and not ignored)
- **Security severity of an already reported update increased** (for example from Low to High)

Updates that were already reported are not mailed again, except as part of the "open updates" overview and reminders.

### Mail layout

Each mail contains up to three sections. Every open update appears in exactly one of them:

1. **Joomla Core**: a separate section for the Joomla update
2. **New updates**: updates that are new or whose severity increased, with a note
3. **Further open updates**: all other pending updates ("Open since <date>")

Each table shows name, type, installed version, available version, security severity and a note. The mail ends with links to the Joomla update list (`…/administrator/index.php?option=com_installer&view=update`) and to the monitor dashboard. If any listed update is security-related, the subject starts with `[SECURITY: <highest severity>]`.

### Reminders

If **Reminder interval** is greater than 0, a reminder listing all open updates is sent when a pending, non-ignored update has been waiting for that many days since the last notification or reminder. Reminders are only sent by scheduled runs, only after a successful check and only if the last successful check is not older than the configured maximum age.

### Reports

- **Weekly report**: sent on the first scheduled run on or after the configured weekday
- **Monthly report**: sent on the first scheduled run on or after the configured day of the month (1–28)

Reports contain the number of pending, newly detected, resolved and ignored updates in the period, followed by the list of open updates. The same freshness rule as for reminders applies.

## Security detection

Security information comes from the **changelog XML** that an extension developer can provide through the `changelogurl` in the update server. The monitor reads the `<security>` section for the available version:

- An optional `severity` attribute on the `<security>` element or on an `<item>` is used.
- Otherwise it looks for the words *critical, high, medium/moderate, low* (and German equivalents) in the item text.
- Security entries without a recognisable level are shown as **Security (level unknown)**.
- Without security metadata nothing is marked. **This does not mean the update is not security-related.**

Detection depends on the metadata provided by extension developers and on what supported Joomla versions publish.

Example of a changelog entry that is recognised:

```xml
<changelogs>
  <changelog>
    <element>com_example</element>
    <type>component</type>
    <version>1.2.3</version>
    <security>
      <item severity="high">Fixed SQL injection in the search form.</item>
    </security>
  </changelog>
</changelogs>
```

## History and resolution

Each combination of extension and available version is one history entry. Status values:

| Status | Meaning |
|---|---|
| **Pending** | The update is available |
| **Resolved** | The update is no longer pending (see reasons below) |
| **Ignored** | Pending, but excluded from notifications, reminders and reports |

Resolution reasons:

| Reason | When |
|---|---|
| `installed` | The installed version reached the available version |
| `removed` | The extension was uninstalled |
| `superseded` | A newer version of the same extension is now available |
| `withdrawn` | The update disappeared from the update list for 3 consecutive successful checks while the extension was not updated |

To avoid wrong "resolved" entries, nothing is resolved after a failed update search. An update that merely vanishes from the list is kept as pending until the checks above confirm otherwise.

## Options

Open the options via the **Options** button in the component toolbar.

| Option | Default | Description |
|---|---|---|
| Search for updates before each check | Yes | Runs the Installer's *Find Updates* before reading the list |
| Read security info from changelogs | Yes | Fetches the changelog XML of every update |
| Site URL | empty | Base URL for mail links, useful for CLI cron |
| Detailed error messages in the activity log | No | Adds file and line to logged errors; only for troubleshooting |
| Maximum age of last successful check (hours) | 48 | Reminders and reports require a check at least this recent |
| Run a check when opening the component | Yes | Never sends emails |
| Send emails after a manual check | Yes | *Run check now* button |
| Send emails after a scheduled check | Yes | Scheduled Tasks plugin; also controls reminders and reports |
| Notify Super Users | Yes | Super Users with *Receive System Emails* |
| Additional recipients | empty | Extra email addresses |
| Notify about new updates | Yes | |
| Notify when security severity increases | Yes | |
| Reminder interval (days) | 7 | 0 disables reminders |
| Weekly report / weekday | No / Monday | |
| Monthly report / day of month | No / 1 | 1–28 |
| Automatic cleanup | Yes | Runs at most once a day |
| Keep activity log (days) | 90 | |
| Keep resolved history (days) | 365 | 0 keeps the history forever |

**Permissions:** `core.manage` allows viewing the monitor. Actions (run check, send test mail, ignore, clear log) require `core.admin`.

## Troubleshooting

- **Nothing is listed on the dashboard:** run **Run check now** and open the **Activity Log**. Each check writes a line such as *Installer update list (#__updates): N entries, … counted*. It shows how many entries Joomla's update table holds, how many belong to installed extensions and how many the monitor took over. Entries for packages that are not installed (for example the full list of translation packs) are ignored on purpose. The line is only marked as a warning if entries of installed extensions could not be matched.
- **"No check has been run yet":** neither the scheduled task nor the manual check has completed. Check that the task exists, is enabled and that the scheduler is actually triggered (lazy scheduler, webcron or cron).
- **No emails:** use **Send test mail**. Check the Joomla mail settings, that at least one active Super User has *Receive System Emails* enabled or an additional recipient is configured, and look for mail errors in the activity log.
- **Links in emails point to the wrong host:** set **Site URL** in the options.
- **No security information:** the extension developer does not publish security metadata in the changelog, or the changelog URL is not reachable.
- **A check seems stuck:** a lock older than 30 minutes is ignored automatically.

## Data stored

The component creates three tables (removed on uninstall):

- `#__updatemonitor_updates`: update history
- `#__updatemonitor_log`: activity log
- `#__updatemonitor_state`: internal state (last check, lock, report markers)

## Security notes

Update data comes from remote update servers, so it is treated as untrusted:

- Only `http://` and `https://` links from update data are stored and rendered (no `javascript:`, `data:` etc.), and external links open with `rel="noopener noreferrer"`.
- Changelog URLs must resolve to **public** addresses only (no private, loopback or reserved ranges). Redirects are followed manually (max 3) and every hop is validated. A changelog can be at most 1 MB, each request times out after 10 seconds and all changelog downloads of one check share a 2-minute budget. If your changelog server is on a private network, security details will not be read from it.
- Changelogs are parsed without network access for XML entities and without XPath on remote values. Documents that declare a DTD or entities are rejected.
- The hostname check resolves the address before the request is made. A malicious update server that controls its own DNS could still answer differently at connection time (DNS rebinding); the impact is limited to a blind GET request, because the response is only read as a changelog and never shown to an attacker.
- Error messages in the activity log do not include file names or line numbers unless **Detailed error messages** is enabled.
- All output in the dashboard and in emails is HTML-escaped; database access uses bound parameters.
- State-changing actions (check, ignore, test mail, clear log) require `core.admin` and a valid form token. The automatic check when opening the component also requires `core.admin` and runs at most once per minute.

No update is ever installed automatically and the monitor never changes extensions or Joomla files.

## Updating the monitor itself (update server)

The package manifest contains an update server entry, so Joomla can offer new versions of Update Monitor for Joomla in the normal update list (and the monitor will list its own updates like any other extension).

The update data lives in this repository:

| File | Purpose |
|---|---|
| `updatemonitor_update.xml` | Joomla update server file (version, download URL, SHA-256, supported Joomla and PHP versions) |
| `updatemonitor_changelog.xml` | Changelog in Joomla's changelog format, read by Joomla and by this monitor |

### Preparation (once)

1. Replace `YOUR_GITHUB_USER/YOUR_REPO` in `pkg/pkg_updatemonitor.xml` and `updatemonitor_update.xml` (also set the maintainer name).
2. Push the repository. The update server URL is then  
   `https://raw.githubusercontent.com/<user>/<repo>/main/updatemonitor_update.xml`

### Releasing a new version

1. Raise `<version>` in `com/updatemonitor.xml`, `plg/updatemonitor.xml` and `pkg/pkg_updatemonitor.xml`, and add an entry to `updatemonitor_changelog.xml`.
2. Run `tools/build.sh`. It builds `dist/pkg_updatemonitor.zip` and writes version, download URL and SHA-256 into `updatemonitor_update.xml`.
3. Create a GitHub release with tag `v<version>` and upload **that exact** `dist/pkg_updatemonitor.zip` (the SHA-256 changes with every build).
4. Commit and push `updatemonitor_update.xml` and `updatemonitor_changelog.xml`.

Installations see the update after their next update search. Security entries in the changelog (`<security><item severity="high">…</item></security>`) are picked up by Joomla Update Monitor installations like those of any other extension.

## Repository layout

```
com/                          component source (admin only)
  updatemonitor.xml
  admin/ (config.xml, access.xml, services, sql, src, tmpl, language)
plg/                          task plugin source
  updatemonitor.xml, services, src, language
pkg/                          package manifest and install script
  pkg_updatemonitor.xml, script.php
tools/build.sh                builds dist/pkg_updatemonitor.zip, refreshes the update XML
updatemonitor_update.xml      Joomla update server file
updatemonitor_changelog.xml   changelog
```

Build the installable package with `tools/build.sh` (requires `bash`, `zip`, `sha256sum`).

PHP namespaces: `Custom\Component\UpdateMonitor` and `Custom\Plugin\Task\UpdateMonitor`. Adjust them (and the author/copyright in the manifests) to your own vendor name before publishing.

## Uninstall

Uninstall the package in **System → Manage → Extensions**. This removes the component, the plugin and the three database tables. Remove the scheduled task manually if it still exists.

## License

GNU General Public License version 2 or later.
