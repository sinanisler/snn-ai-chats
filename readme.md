# SNN AI CHAT - Advanced AI Chat Plugin for WordPress

Create one or multiple chats for your WordPress site.

![image](https://github.com/user-attachments/assets/459b89e6-efb3-4faa-9cbe-c19d4bf5d956)


SNN AI CHAT is an advanced WordPress plugin that seamlessly integrates AI-powered chat functionalities into your website. It supports both **OpenRouter** and **OpenAI** APIs, allowing you to choose your preferred AI model for intelligent and dynamic conversations with your users.

This plugin provides a comprehensive dashboard to monitor usage, manage chat configurations, and customize the appearance of each chat widget. You can define specific system prompts, initial messages, and even set up detailed display conditions for where each chat should appear on your site.

**Key Features:**

* **Flexible AI Provider Support:** Connects with OpenRouter and OpenAI APIs.
* **Customizable Chat Instances:** Create multiple chat configurations, each with its own settings.
* **Granular Control over AI Models:** Select a specific AI model for each chat, or use a global default.
* **Personalized Chat Experience:** Set unique system prompts and initial messages for different chatbots.
* **Styling & Appearance Options:** Customize chat widget position, colors (primary, secondary, text, background, input), font size, and border radius to match your brand.
* **Display Conditions:** Control where chat widgets appear (all pages, specific pages/posts, home, front page, posts, pages, categories, archives) with exclusion rules.
* **Usage Limits & Rate Limiting:** Prevent abuse by setting limits on tokens per session, daily tokens per IP, daily chats per IP, and messages per minute.
* **User Information Collection:** Optionally collect user names and emails before starting a chat session.
* **Comprehensive Dashboard:** View statistics on active chats, total tokens used, total sessions, and today's/month's usage.
* **Detailed Chat History:** Keep a record of all chat sessions, including user messages, AI responses, and tokens used.
* **Live Preview:** See your chat widget styling and initial messages in real-time within the admin panel.

Empower your website with intelligent conversational AI, enhance user engagement, and provide instant support with SNN AI CHAT.

---

## Installation

### Minimum Requirements

* WordPress 5.0 or greater
* PHP version 7.4 or greater (PHP 8+ recommended for `str_contains`)
* MySQL version 5.6 or greater OR MariaDB version 10.1 or greater
* An API key from either [OpenRouter.ai](https://openrouter.ai/) or [OpenAI.com](https://openai.com/).

### Automatic Installation

1.  Navigate to the 'Plugins' menu in your WordPress dashboard.
2.  Click 'Add New'.
3.  Search for "SNN AI Chat" (if available on WordPress.org) or upload the plugin zip file.
4.  Click 'Install Now'.
5.  After installation, click 'Activate'.

### Manual Installation

1.  Download the plugin zip file from the WordPress plugin repository or source.
2.  Unzip the file.
3.  Upload the `snn-ai-chat` folder to the `/wp-content/plugins/` directory via FTP or your hosting file manager.
4.  Activate the plugin through the 'Plugins' menu in your WordPress dashboard.

### After Activation

Once activated, go to **SNN AI Chat > Settings** to configure your API keys and default models. You can then create and customize your chat instances under **SNN AI Chat > Chats**.

---

## Frequently Asked Questions

### Q: What are the main features of SNN AI Chat?
A: SNN AI Chat offers integration with OpenRouter and OpenAI, allowing you to create multiple customizable chat instances. You can set system prompts, initial messages, define display conditions (which pages the chat appears on), configure styling (colors, sizes), set usage limits (tokens, sessions, rate limits), and optionally collect user information. It also includes a dashboard for statistics and chat history.

### Q: Do I need an API key to use this plugin?
A: Yes, you will need an API key from either OpenRouter.ai or OpenAI.com to power the AI responses. You can configure this in **SNN AI Chat > Settings**.

### Q: How do I select which AI model to use?
A: In **SNN AI Chat > Settings**, you can choose your primary API provider (OpenRouter or OpenAI) and select a default model for your entire site. When creating or editing individual chats under **SNN AI Chat > Chats**, you can override the global model and select a specific one for that chat instance.

### Q: Can I customize the appearance of the chat widget?
A: Absolutely! Each chat instance has extensive styling options, including chat position, primary/secondary colors, text color, widget background, input background/text colors, font size, border radius, and widget dimensions.

### Q: How do I control where the chat widget appears on my website?
A: In the "Display Settings" section of each chat's edit page, you have several options:
* **Show on all pages:** Displays the chat on every page (except those explicitly excluded).
* **Display On Specific Pages:** Enter a comma-separated list of page/post IDs.
* **Exclude Pages:** Enter a comma-separated list of page/post IDs where the chat should **never** appear (this overrides other display settings).
* **Template Conditions:** Check boxes to show the chat on your home page, front page, individual posts, pages, categories, or archives.

The plugin processes these rules with exclusions taking the highest priority, followed by "show on all pages," then specific page inclusions, and finally template conditions.

### Q: What kind of usage limits can I set?
A: You can set:
* **Max Tokens Per Session:** Limits the number of tokens used in a single chat conversation.
* **Max Tokens Per IP Daily:** Limits the total tokens an IP address can consume in a 24-hour period across all chat instances.
* **Max Chats Per IP Daily:** Limits the number of new chat sessions an IP address can start in a 24-hour period.
* **Rate Limit Per Minute:** Limits the number of messages a user can send within a 60-second window.

### Q: How can I view chat history and statistics?
A: The plugin provides a **Dashboard** under "SNN AI Chat" which shows overall statistics like active chats, total tokens, and session counts. The **Chat History** page lists all recorded chat sessions, along with user details, message counts, and tokens used, allowing you to view details for each session.

### Q: Is conversation history maintained?
A: Yes, each chat configuration has an option "Keep conversation history during session". If enabled, the AI will remember previous messages in the current session up to a certain limit (default 10 messages, configurable through the AI model's context window and the "Max Tokens Per Session" setting).

### Q: Does this plugin store user data?
A: Yes, if the "Collect user name and email before starting chat" option is enabled for a chat, it will store the provided name and email along with the session and message data in your WordPress database. IP addresses are also stored for rate limiting.

---

## Screenshots

*(Currently, no screenshots are provided. You would typically add `screenshot-1.png`, `screenshot-2.png`, etc., to your plugin directory and list them here.)*

---

## Changelog

### 1.0.1 - 2025-07-07
* **Bug Fix:** Addressed `str_contains` deprecated notice by casting `$hook` to string.
* **Improvement:** Explicitly cast variables to string before using functions that expect string type to improve PHP 8.x compatibility.
* **Feature:** Implemented live preview functionality for individual chat configurations within the admin edit screen.
* **Enhancement:** Refined the `should_show_chat` logic for clearer precedence of display conditions (exclude > all pages > specific pages > template conditions).
* **Refinement:** Updated nonce verification to use a unified nonce for all AJAX actions for better consistency.
* **UI:** Added tooltips to chat settings using Tippy.js for better user guidance.
* **Admin:** Improved styling and layout of admin pages using Tailwind CSS classes for better readability and user experience.
* **Code:** Ensured `wp_parse_args` always receives an array for chat settings.
