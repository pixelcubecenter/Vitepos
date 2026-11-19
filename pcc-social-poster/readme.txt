=== PCC Social Poster ===
Version: 2.15.1

v2.15.1: Products added from the Vitepos POS now auto-post a story too (new setting in
Settings -> Stories, on by default). Only newly created products count, and only when a
person is logged in, so importer plugins still never auto-post. Settings -> Stories shows
a short log of what happened to recent POS products.


v2.15.0: Instagram Story + Facebook Page Story. New "Stories" settings (own 9:16 template,
box positions, colors, platforms). New "Story" section on Generate & Post. New "Story Post"
column on the Products list (one-click "Post to Story"). New "Story Post" box on the product
screen with an "Auto Post" checkbox (on for new products): a story posts automatically when
you publish a product yourself; products created by importer plugins are never auto-posted.
Stories are posted first, skip the minutes-between-posts gap and the daily limit, and show in
Queue & History as "IG Story" / "FB Story".


v2.14.1: Instagram and Threads logins (valid 60 days) are now renewed automatically
every week, so posting no longer stops with "Session has expired". If a renewal fails,
an admin notice tells you to Reconnect.

v2.14.0: Mastodon added (Settings → Mastodon): works with any server using an access
token; images upload directly; limits (images/characters per post) are read from
your server and batches become a thread (caption on the first post).

v2.13.2: Pinterest Sandbox mode can use a token generated in the Pinterest developer
portal (Settings → Pinterest → Sandbox access token) when the sandbox rejects the
normal login token ("Authentication failed").

v2.13.1: Pinterest Trial apps must use Pinterest's sandbox address. New "Mode" setting
(Sandbox for Trial, Production after Standard access), a "Create board" button,
and automatic switch to Sandbox when Pinterest says the app is on Trial.

v2.13.0: Bluesky added (Settings → Bluesky): login with an App Password; 4 images per
post, so up to 10 products become a thread of up to 3 posts; captions are
shortened to 300 characters; your website address is made clickable.

v2.12.0: Pinterest added (Settings → Pinterest): connect with OAuth, pick a board;
1 image = one Pin, 2-5 = a carousel Pin, more = several Pins; each links to the
product page. Pins are only public once Pinterest grants Standard access.

v2.11.0: Tumblr added (Settings → Tumblr): connect once with OAuth, each post is up
to 10 photos + the caption, uploaded directly to Tumblr. Choose published,
draft or private, and optional tags.

v2.10.0: "Telegram Private" added — posts the images with NO text to a Telegram
group (Settings → Telegram Private). Uses the channel's bot unless you give it
its own token.

v2.9.1: "Process next post now" now skips the minutes-between-posts wait and the
daily cap, and tells you what happened (it used to do nothing, silently, while
the wait was running).

v2.9.0: Telegram channel posting added (Settings → Telegram channel). Images are
uploaded straight to Telegram, so it works even when a host blocks Meta/TikTok
crawlers from your uploads folder.

v2.8.0: Gemini and OpenAI removed — product images are always built from your
template (no AI). Background checks run every 5 minutes (was every minute) and
do a single query when the queue is empty; queuing a post starts it within
seconds. Template background removal is ~6x faster and uses ~60x less memory.
Saved images only build the small thumbnail. Upgrading also deletes any stored
Gemini/OpenAI API keys.

WHAT IT DOES
-------------
1. Social Poster → Generate & Post: pick a WooCommerce product category, select which products, click "Queue selected products".
2. Up to 10 selected products become ONE Instagram post: 1 product = a normal
   single-image post, 2-10 products = a carousel (swipeable multi-photo post),
   using an auto-built caption listing every product and price. More than 10
   selected is automatically split into multiple posts.
3. A WP-Cron check (every 5 minutes, plus one a few seconds after you queue
   something) picks the oldest queued post, generates every
   product's image, then publishes to Instagram — spaced out by your configured
   minutes-between-posts and a daily cap.
4. Watch progress on Queue & History (auto-refreshes). Failed posts show the
   error and can be retried (only the products that actually failed are
   re-generated — already-generated images in that post are kept).

IMAGES
------
Each product image is made from a fixed template PNG you design yourself — no
API calls, no cost, fast. The plugin removes the product photo's background
itself (see below) and drops in the product's category, title and price at
positions you configure. Best for product photos on a plain/solid background.

SETUP — TEMPLATE IMAGE
--------------------------
1. Design your branded frame in any image editor (logo, social icons, "48h"
   badge, price pill, website/phone bar) with the product photo, category,
   title, and price areas left blank — export as a PNG, ideally 1200x1200.
2. Settings → Template image → upload it as the "Template base image".
3. Measure where each blank area sits on your PNG (x,y,width,height in
   pixels from the top-left) and enter those 4 boxes in Settings. If you're
   unsure of exact pixel coordinates, open your PNG in any editor that shows
   a pixel ruler/coordinates (Photoshop, GIMP, even Figma) and read them off.
4. Adjust the two text colors and the background-removal sensitivity if
   needed, then Save.

Background removal here is a simple corner-color flood fill: it detects your product photo's background color from its
corners and clears any background-colored area connected to the image edges.
It works well for typical studio photos on a plain white/solid background,
and won't cleanly separate products photographed against busy or gradient
backgrounds — for those, photograph on a plain background.

SETUP — TELEGRAM CHANNEL
-------------------------
No approval or app review is needed.
1. In Telegram open @BotFather → /newbot → follow the steps → copy the token.
2. Open your channel → Administrators → Add Administrator → choose your bot →
   keep "Post Messages" allowed.
3. Settings → Telegram channel: paste the token and the channel (public
   channel: @username; private channel: the -100… number — post something in
   the channel, then open https://api.telegram.org/bot<TOKEN>/getUpdates and
   read "chat":{"id":…}). Save, then click "Test Telegram connection".
Posts: 1 image = a photo; 2-10 images = one album. Telegram limits captions to
1,024 characters, so longer text is sent as a normal message right after the
photos. The token is only used for api.telegram.org calls.

TELEGRAM PRIVATE (group, images only)
Add the bot to your group as a member, enter the group ID (negative number) in
Settings → Telegram Private, and tick "Telegram Private" when posting. The
images go out as a photo/album with no caption and no text message. Optional:
give it its own bot token; otherwise the channel's bot is used.

SETUP — TUMBLR
---------------
No approval needed.
1. tumblr.com/oauth/apps → Register application. Use your website address and
   a short description. Put the redirect URL shown in Settings → Tumblr in BOTH
   "Default callback URL" and "OAuth2 redirect URLs".
2. Copy the OAuth Consumer Key and (Show secret key) the Secret Key into
   Settings → Tumblr, Save, then click "Connect Tumblr" and allow access.
   The blog name fills in automatically (primary blog) if left empty.
3. Pick Published / Draft / Private and optional tags. A post = up to 10
   photos followed by the caption. Tumblr logins last only about 40 minutes,
   so the plugin refreshes it automatically before each post.

SETUP — PINTEREST
------------------
1. developers.pinterest.com → My apps → Connect app (Trial access is granted
   after Pinterest reviews your app description).
2. In the app settings add the redirect URI shown in Settings → Pinterest.
3. Copy the App ID and App secret key into Settings → Pinterest, Save, click
   "Connect Pinterest", choose a board, Save again.
4. IMPORTANT: on Trial access the plugin must use Sandbox mode (the default):
   Pins/boards made there are test data and are NOT shown publicly on Pinterest.
   After Standard access is granted, switch Mode to Production and pick a real board. To make them public, click Upgrade on the app card in the Pinterest
   developer portal and submit the demo video Pinterest asks for (screen
   recording of: this plugin → Connect Pinterest → Pinterest consent screen →
   queue a post → the Pin appearing on the board).
Pinterest carousels hold 2-5 images, so up to 10 products become up to 2 Pins.
Pins link to the product pages. Titles are limited to 100 characters and
descriptions to 800, so long captions are shortened for Pinterest only.

SETUP — BLUESKY
----------------
No approval needed.
1. In the Bluesky app: Settings → Privacy and security → App passwords → Add
   App Password. Copy it (xxxx-xxxx-xxxx-xxxx). Never use your main password.
2. Settings → Bluesky: enter your handle (yourname.bsky.social) and the App
   Password, Save, click "Test Bluesky login".
Limits handled for you: images are recompressed under Bluesky's 1 MB limit, 4
images per post (a batch of up to 10 becomes a thread of up to 3 posts, caption
on the first), text is cut to 300 characters, and your website address is
turned into a link.

SETUP — INSTAGRAM (one-click connect from the plugin)
--------------------------------------------------------
One-time setup on Meta's side:
1. https://developers.facebook.com/apps → Create App ("Business" type) or use
   an existing app.
2. Add the "Instagram API" use case.
3. "API setup with Instagram login" → Permissions and features → add
   instagram_business_basic and instagram_business_content_publish.
4. "Roles" → add your store's Instagram account as an "Instagram Tester" →
   accept the invite in the Instagram app (Settings → Apps and websites →
   Tester invites).
5. Copy the "Instagram app ID" and "Instagram app secret" from the same
   "API setup with Instagram login" page.
6. "4. Set up Instagram business login" → Business login settings → paste
   the Redirect URI shown in this plugin's Settings → Instagram section into
   "Valid OAuth Redirect URIs". Save.

One-time setup in the plugin:
1. Settings → Instagram → paste the App ID and App Secret → Save.
2. Click "Connect Instagram Account", log into Instagram, approve. You land
   back on Settings as "Connected" with a 60-day token stored automatically.
3. Click "Reconnect" any time it's close to expiring.

No Facebook Page is required for this flow.

SETUP — REDDIT (optional, one-click connect from the plugin)
---------------------------------------------------------------
Reddit requires approval before any API use: request access through Reddit's
developer support process (see Reddit's Responsible Builder Policy) and
describe it honestly as posting your own store's images to your own profile /
subreddit. Then:
1. reddit.com/prefs/apps → create app, type "web app", redirect uri = the one
   shown in the plugin's Settings → Reddit section.
2. Settings → Reddit → paste Client ID (under the app name) and Secret → Save.
3. Optionally enter a Subreddit (no "r/"); empty posts to your own profile.
4. Click "Connect Reddit" and allow. The connection renews itself.
One image = an image post; 2+ = a gallery. The first line of the text is the
title; the full text is added as the first comment. Many subreddits ban
self-promotion or require flair, so a post can be rejected there.

POST MANUALLY
--------------
Social Poster → Post Manually: pick one or more images from the Media Library
(up to 10), type your own text, tick the platforms, and queue. Images are posted
as-is (no template/logo added). WebP/AVIF images are converted to PNG
automatically because Instagram/Facebook/LinkedIn reject them. Manual posts
appear in Queue & History with method "manual" and follow the same pacing,
retry and cancel rules.

POSTING PACE
-------------
Settings → "Minutes between posts" and "Max posts per day" throttle the
queue so a big batch of posts doesn't all go out back-to-back.

No Facebook Page is required for the Instagram flow above.

SETUP — FACEBOOK PAGE (separate, optional, one-click connect from the plugin)
----------------------------------------------------------------------------
This uses classic Facebook Login for Business — a different flow from the
Instagram one above, and needs its own App ID/Secret (can be a second use case
added to the same Meta app, or a separate app).

One-time setup on Meta's side:
1. In your Meta app (or a new one) → add the "Facebook Login for Business" use
   case, and request these permissions: pages_show_list,
   pages_manage_posts, pages_read_engagement.
2. In that use case's settings, paste the Redirect URI shown in this plugin's
   Settings → Facebook Page section into "Valid OAuth Redirect URIs". Save.
3. Copy that app's App ID and App Secret.

One-time setup in the plugin:
1. Settings → Facebook Page → paste the App ID and App Secret → Save.
2. Click "Connect Facebook Page", log in, approve. If you manage more than one
   Page, you'll be asked which one to use. You land back on Settings as
   "Connected to Page: ...".

CHOOSING WHERE TO POST
------------------------
On Generate & Post, tick "Instagram", "Facebook Page", "TikTok", and/or
"Threads", and/or "LinkedIn Page" (only connected platforms are selectable) before queuing. Each
queued post tries every ticked platform independently — if one succeeds and
another fails, the post shows as "partial" in Queue & History, and Retry only
re-attempts the platform(s) that actually failed.

SETUP — TIKTOK (optional, one-click connect from the plugin)
-----------------------------------------------------------------
Important: until your TikTok app passes TikTok's own audit, every post it
makes is forced to private/self-only visibility — visible only in your own
TikTok account, not the public. This is a TikTok requirement, not something
this plugin can work around. You can still connect and test the whole flow
before that audit passes; the posts just won't be public yet.

One-time setup on TikTok's side:
1. https://developers.tiktok.com → register as a developer, create an app.
2. Add the "Login Kit" and "Content Posting API" products to the app.
3. Under Login Kit, paste the Redirect URI shown in this plugin's Settings →
   TikTok section into the app's Redirect URI field.
4. TikTok requires verifying the domain(s) used for both the redirect URI
   and for pulling images (your site's domain) — follow TikTok's domain
   verification steps in the app dashboard (usually a meta tag or file
   upload to your site root).
5. Request the video.publish and user.info.basic scopes.
6. Copy the app's Client Key and Client Secret.

One-time setup in the plugin:
1. Settings → TikTok → paste the Client Key and Client Secret → Save.
2. Click "Connect TikTok", log in, approve. You land back on Settings as
   "Connected".

Post mode (Settings → TikTok): "Draft" (default) sends each post to your TikTok
inbox as a draft — open TikTok → Inbox, review it and tap Post. It needs the
video.upload scope (enabled on the app by default) and no audit; click
Reconnect TikTok once after updating. "Direct Post" publishes automatically but
TikTok requires an audit for that (and forbids logos/branding in the images).

Posts are photo carousels (TikTok's equivalent of Instagram's carousel) built
straight from the same generated images as the other platforms — 1 product is
a 1-photo post, several products become a multi-photo post.

When you're ready to make posts public, submit your TikTok app for their
audit from the TikTok developer dashboard — nothing in this plugin needs to
change once that's approved, TikTok will simply start allowing public
visibility.

SETUP — THREADS (optional, one-click connect from the plugin)
-------------------------------------------------------------------
Uses Meta's Threads API — a separate app/permission set from Instagram and
Facebook, even though it's set up in the same kind of Meta developer app. No
linked Instagram account is required.

One-time setup on Meta's side:
1. In your Meta app (can be the same one used for Instagram/Facebook, or a
   new one) → Use cases → add "Access the Threads API".
2. Under its settings, add the threads_basic and threads_content_publish
   permissions.
3. Paste the Redirect URI shown in this plugin's Settings → Threads section
   into the Threads Login's Valid OAuth Redirect URIs. Save.
4. Copy the app's App ID and App Secret (same place as Instagram/Facebook's,
   under App Settings → Basic).
5. As with the other Meta products, if the app is still in Development mode,
   add the Threads account you'll log in with as a Tester under App Roles →
   Roles, so login isn't blocked before any review.

One-time setup in the plugin:
1. Settings → Threads → paste the App ID and App Secret → Save.
2. Click "Connect Threads", log in, approve. You land back on Settings as
   "Connected".

Threads text posts are limited to 500 characters — the plugin trims the
caption automatically if needed, so nothing gets rejected for length.

SETUP — LINKEDIN COMPANY PAGE (optional, one-click connect from the plugin)
-------------------------------------------------------------------------------
Posting to a Company Page requires LinkedIn's Community Management API
(scope w_organization_social). LinkedIn reviews and approves this per app —
it can take days — and posting will fail until it's approved.

One-time setup on LinkedIn's side:
1. https://www.linkedin.com/developers/apps → Create app. Link it to your
   Company Page and have a Page Super admin verify the association.
2. Products tab → request "Community Management API" (fill in the access
   form: use case = posting your own store's products to your own Page).
   Note: LinkedIn wants Community Management to be the app's only product,
   so use a dedicated app rather than one with "Sign In with LinkedIn".
3. Auth tab → paste the Redirect URI shown in this plugin's Settings →
   LinkedIn section into "Authorized redirect URLs". Copy the Client ID and
   Client Secret.
4. Your Page ID is the number in linkedin.com/company/<ID>/admin/.

One-time setup in the plugin:
1. Settings → LinkedIn → paste Client ID, Client Secret and Page ID → Save.
2. Click "Connect LinkedIn" (log in as a Page admin, approve). Tokens last
   about 60 days — click Reconnect before expiry (the Settings page shows the
   date).

One product = a single-image post; 2–10 products = a multi-image post.

LIMITATIONS (v2)
------------------
- Instagram feed posts only (single image or carousel) — no Stories/Reels yet.
- Carousels max out at 10 items (Instagram's own limit); larger selections
  are automatically split into multiple posts.
- Template background removal is a simple color-based flood fill — works best
  on plain-background product photography.
