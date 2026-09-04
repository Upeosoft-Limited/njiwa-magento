# Njiwa for Magento 2

WhatsApp your customers when their order is placed, paid, shipped, cancelled
or refunded, and get a message yourself when one comes in.

## Install

Magento Open Source or Adobe Commerce 2.4, PHP 7.4 or newer.

By Composer, which is the tidy way and the one that lets you remove it again.
This module is not on Packagist, so Composer has to be told where it lives
before it can be asked for by name:

```
composer config repositories.njiwa vcs https://github.com/Upeosoft-Limited/njiwa-magento
composer require upeo/module-njiwa
```

The repository is public, so Composer needs no token and no `auth.json`. It does
read versions from git tags, so `require` finds nothing until a release is
tagged; until then ask for the branch by name:

```
composer require upeo/module-njiwa dev-main
```

Or copy this folder to `app/code/Upeo/Njiwa` in your Magento installation.

Either way, then:

```
bin/magento module:enable Upeo_Njiwa
bin/magento setup:upgrade
bin/magento setup:di:compile      # production mode only
bin/magento cache:flush
```

`setup:upgrade` creates the one table this module has, `upeo_njiwa_message`.
That table is what stops a customer being messaged twice, so the module is not
finished installing until it exists.

The settings are at **Stores → Configuration → Sales → Njiwa WhatsApp**.

If a colleague cannot see that section, their admin role is missing
**Njiwa WhatsApp Section** under System → Permissions → User Roles → Role
Resources.

## Something has to be running the queue

Nothing is sent from the checkout. When an order reaches one of the moments
below, the finished message is published to the queue topic `upeo.njiwa.send`,
and a worker sends it a moment later. That is deliberate: a slow network, or
Njiwa being down, cannot delay or break an order.

The consumer is called **`upeo.njiwa.send`**. You can see it in the list
Magento keeps:

```
bin/magento queue:consumers:list
```

**If Magento's cron is running, there is nothing to set up.** Magento starts
each consumer every minute, the same machinery that sends your order emails,
and each run stops after a batch of messages and is started afresh the next
minute.

**If it is not, nothing is sent and nothing is lost** — the messages sit in the
queue (the `queue_message` table on a plain install, or in RabbitMQ if you have
one) until something drains them. Two things cause it:

- Cron is not installed at all. `bin/magento cron:install` and check that
  `bin/magento cron:run` is in the web user's crontab.
- `cron_consumers_runner` is switched off in `app/etc/env.php`. Some hosts,
  Adobe Commerce cloud among them, set `'cron_run' => false` and run consumers
  themselves as long-lived processes. If that is your host, add this one to
  whatever supervises them:

  ```
  bin/magento queue:consumers:start upeo.njiwa.send
  ```

  And if that file lists individual consumers under `'consumers' => [...]`,
  `upeo.njiwa.send` has to be in the list, or it is the one consumer that never
  starts.

If you ever wonder whether a message went out, `var/log/njiwa.log` says so, and
so do the order's own comments.

## Set it up

Paste your API key from [console.upeo.ai](https://console.upeo.ai) → API keys
and save. Magento stores it encrypted, with the rest of your credentials.

**Start with a test key.** A key beginning `sk_test_` checks and stores every
message and delivers nothing. Turn on the events you want, place a test order,
then read the order's **Comments History** and `var/log/njiwa.log`. Both say
what was sent, to whom, and Njiwa's own id for it, and a test send is labelled
"Test key, so nothing reached WhatsApp." Only when that reads right, swap in
the `sk_live_` key. A live key sends real messages, and real messages cost
money.

### The two buttons

Under the connection settings, once you have saved a key:

**Test connection** asks Njiwa which numbers this key can send from and sends
nothing at all. It lists each one, whether it is linked yet, and says plainly
when the key is a `sk_test_` key. It also warns you when **Send from** names a
number this account does not have, which is the one setting that looks right on
the page and then refuses every message.

**Send a test message** sends one real message to one number you type in. Put
in your own number. The wording is fixed in the code, so you supply the
recipient and nothing else, and on a live key it is a real message to a real
phone.

Both read the settings **as they are saved, not as they are on screen**, so
save first and then check. Both need the same permission as the settings page
itself, and the second one refuses another message within a few seconds of the
last, so a stuck key cannot put a row of them on somebody's phone.

Neither replaces a test order. A test key and one real order exercise the
observers, the queue and the consumer as well as the key, and only that proves
the whole path.

Every field on the page explains itself; the short version:

| Setting | What it is for |
| --- | --- |
| Send WhatsApp messages | The master switch, on from the start. No keeps every setting and sends nothing. |
| API key | `sk_test_` delivers nothing, `sk_live_` sends for real. |
| Njiwa address | Leave it alone unless you were given your own. |
| Send from | Which of your numbers sends, digits only. Empty means the account default. |
| Each event | Yes, No, and the exact wording. Empty wording sends nothing. |
| Tell me about new orders | The one message that comes to you. |
| Your WhatsApp numbers | Where that message goes. Several, comma separated. |

The master switch arrives on and every event arrives off, which is why a fresh
install sends nothing: it has no key to send with and nothing it has been told
to send. Turning an event on is the deliberate act, and it is the only one.

Every setting can be set per website and per store view. A shop selling in two
countries can give each store its own wording, its own numbers, or its own
Njiwa account, and each message is composed with the settings of the store the
order was actually placed in.

## What gets sent, and when

| When | Who hears about it |
| --- | --- |
| The order is placed | The customer: we have your order, waiting for payment |
| An invoice is paid | The customer: payment received, getting it ready |
| A shipment is created | The customer: it is on its way |
| The order is cancelled | The customer: cancelled, and you were not charged |
| A credit memo is created | The customer: the money is coming back |
| The order is placed | You: a new order came in |

Each one is off until you turn it on.

The message to you goes out **once per order**, when the order is placed. That
is the first moment an order is real in Magento; everything before it is a
quote, and a quote is somebody who reached the payment page and may never come
back.

A shop that takes card payment at the checkout will see **Order placed** and
**Payment received** happen within a second of each other. Most shops want
Payment received and not both. Order placed earns its place on bank transfer
and cash on delivery, where the order sits waiting for money.

An order shipped in two parcels messages the customer once, on the first
shipment. Saving a shipment again to add a tracking number does not message
anybody a second time either.

The refund message is sent on the first credit memo. Worth knowing before you
switch it on: `{order_total}` is the total of the order, not the amount
refunded, so the wording that ships with this module is right for a full refund
and overstates a partial one. If you refund parts of orders, say so in the
wording rather than quoting a figure.

## The wording

Plain text with placeholders in braces. The settings page lists them all; they
are `{first_name}`, `{last_name}`, `{customer_name}`, `{order_number}`,
`{order_total}`, `{order_date}`, `{order_status}`, `{payment_method}`,
`{items}`, `{item_count}` and `{shop_name}`.

A placeholder that does not exist, `{order_no}` say, is removed before sending
rather than posted to a customer, and a line is written to `var/log/njiwa.log`
telling you where to look.

There is no `{order_url}` and no `{admin_url}`. Magento cannot produce either
honestly: the customer's own order page needs a logged-in customer, which a
guest checkout does not have, and an admin URL carries a secret key tied to the
session of whoever generated it. A link that lands somebody on a login screen
is worse than no link.

`{order_total}` is written in the currency the customer was charged in, and
`{order_date}` in the store's own timezone, not the server's.

## Things worth knowing

**The checkout never waits.** Everything this module does inside an order is
wrapped so that it cannot throw: if the module has a bad afternoon, the line is
written to `var/log/njiwa.log` and the order carries on as though it were not
installed.

**Every send is written on the order.** Open any order and its Comments History
says what went where, with Njiwa's message id, or why it did not.

**Nothing is sent twice.** Before a message is attempted, a row is claimed in
`upeo_njiwa_message` against the store, the order number, the event and the
recipient. A second attempt at the same one collides with that row and stops.
The message also carries an idempotency key, which Njiwa honours for
twenty-four hours, so even a job that runs twice inside a minute replays the
first answer instead of messaging the customer again.

**A refusal is not retried.** If Njiwa says no — no credit, a number that is
not linked, a recipient WhatsApp does not know — the reason is written on the
order and in the log, and that is the end of it. It would say no again.

**Numbers are taken as typed, punctuation removed.** The billing telephone
first, the shipping one if there is no billing number. `0712 345 678` becomes
`0712345678` and Njiwa reads it against your own sending number's country. A
number written in full international form is left as it is. Anything with an
`@` in it is refused outright, because that is how a WhatsApp group is
addressed and this module must never post to a group.

**A customer with no phone number is normal.** Nothing is sent, and nothing is
complained about.

**Everything this module logs goes to `var/log/njiwa.log`**, not into
`system.log` with the rest of Magento, so you can read it, or send it to us,
without sending the log of your whole shop.

**Removing it.** `bin/magento module:disable Upeo_Njiwa` stops it and keeps
everything. If you delete the files instead, your key stays encrypted in
`core_config_data` and the `upeo_njiwa_message` table stays where it is; this
module ships no uninstall script, so `bin/magento module:uninstall Upeo_Njiwa`
is what removes them, and it only works for a module installed by Composer.
Order comments stay either way, because they are a record of what was sent and
they belong to the order.

## What it does not do

**It does not receive replies.** Inbound WhatsApp arrives as a webhook and
verifying one needs that number's signing secret, which the console does not
yet show. Until it does, a receiving feature could not check that a request
really came from Njiwa, so there is not one.

**It does not keep a copy of your messages.** The table it keeps holds who was
messaged about which order and what became of it. The messages themselves live
in the Njiwa console, where they are already searchable.

**It does not run campaigns.** Bulk sending to past customers is what the Njiwa
console is for, on Business plans and above.

---

Docs: https://docs.njiwa.upeo.ai · Console: https://console.upeo.ai
UPEO.AI · hello@upeo.ai · 0116888777 on WhatsApp
