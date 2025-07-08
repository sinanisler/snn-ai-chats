# SNN AI CHAT - Advanced AI Chat Plugin for WordPress

A feature-rich WordPress plugin that integrates advanced AI chat capabilities into your website, supporting both OpenRouter and OpenAI models. Engage your users with dynamic, intelligent conversations, customizable chat widgets, and comprehensive usage analytics.


---

## Screenshots

<img src="https://github.com/user-attachments/assets/9d49bab0-b41f-4923-939a-535376c1ef09"  width="32%">

<img src="https://github.com/user-attachments/assets/2269bd6c-1ad6-4621-b448-7a32a7bc6aeb"  width="32%">

<img src="https://github.com/user-attachments/assets/35be720b-d176-4cd1-b7ee-f0ef25ad9e6e"  width="32%">



---


## Description

The SNN AI CHAT plugin brings the power of artificial intelligence directly to your WordPress website. Whether you want to offer instant customer support, provide an interactive FAQ, or simply enhance user engagement, SNN AI CHAT offers a flexible and robust solution. It seamlessly integrates with leading AI providers like OpenRouter and OpenAI, allowing you to choose the best model for your needs.

With an intuitive admin interface, you can create and manage multiple chat instances, each with its own settings, appearance, and AI personality. The plugin also includes comprehensive logging and analytics to help you monitor usage and optimize your AI interactions.

 

---

## Features

* **Dual AI Provider Support:**
    * **OpenRouter Integration:** Connect to a wide range of models available through OpenRouter.
    * **OpenAI Integration:** Utilize powerful OpenAI models like GPT-4o-mini.
* **Centralized API Settings:** Configure your OpenRouter and OpenAI API keys and default models from a single settings page.
* **Customizable Chat Instances:**
    * Create multiple, independent chat widgets for different purposes or pages on your site.
    * Each chat can have its own **name, initial message, and system prompt** to define its unique personality and function.
    * Option to **keep conversation history** for multi-turn dialogues within a session.
    * Ability to specify a **particular AI model** for each chat, overriding the global default if needed.
* **Advanced API Parameter Control (Global):** Fine-tune AI responses with adjustable parameters:
    * **Temperature:** Controls randomness (0.0 - 2.0).
    * **Max Response Tokens:** Sets the maximum length of AI replies.
    * **Top P:** Nucleus sampling control (0.0 - 1.0).
    * **Frequency Penalty:** Reduces repetition of frequent tokens (-2.0 to 2.0).
    * **Presence Penalty:** Encourages new topics (-2.0 to 2.0).
* **Extensive Display Conditions:** Control exactly where each chat widget appears on your site:
    * Show on **all pages**.
    * Display on **specific pages/posts** by ID.
    * **Exclude specific pages/posts** by ID (highest priority).
    * Show based on **WordPress template conditions** (Home, Front Page, Posts, Pages, Categories, Archives).
* **Visual Customization:** Tailor the chat widget's appearance to match your website's design:
    * **Chat Position:** Choose from Bottom Right, Bottom Left, Top Right, Top Left.
    * **Color Pickers:** Customize primary, secondary, and text colors for various chat elements (toggle button, headers, user/AI messages, input fields).
    * **Sizing Controls:** Adjust widget width, height, font size, and border-radius.
* **Robust Usage Limits & Rate Limiting:**
    * Set **max tokens per session**.
    * Limit **max tokens per IP daily**.
    * Limit **max chat sessions per IP daily**.
    * Define a **rate limit per minute** to prevent abuse.
* **User Information Collection (Optional):** Option to require users to provide their name and email before initiating a chat.
* **Comprehensive Chat History:** View detailed logs of all chat sessions, including user information, messages, responses, and token usage.
* **Live Preview:** See how your chat widget will look and behave in real-time within the chat editing interface.
* **Admin Dashboard Statistics:** Get an overview of your chat plugin's performance with key metrics like active chats, total tokens, and daily/monthly sessions.

---

## Installation

### Minimum Requirements

* WordPress 5.0 or higher
* PHP 8.0 or higher
* An API Key from [OpenRouter.ai](https://openrouter.ai/) or [OpenAI](https://openai.com/)

### Installation Steps

1.  **Upload:**
    * Download the plugin ZIP file.
    * Go to your WordPress admin dashboard.
    * Navigate to **Plugins > Add New > Upload Plugin**.
    * Click "Choose File" and select the downloaded ZIP.
    * Click "Install Now".
2.  **Activate:**
    * Once installed, click "Activate Plugin".
3.  **Configure API Keys:**
    * Go to **SNN AI Chat > Settings** in your WordPress admin menu.
    * Select your preferred **AI Provider** (OpenRouter or OpenAI).
    * Enter your corresponding **API Key**.
    * Choose a **default model** for your selected provider.
    * Configure **shared API parameters** like Temperature, Max Tokens, etc.
    * Click "Save Settings".

---

## Configuration

After activating the plugin, you'll find the SNN AI Chat menu in your WordPress admin sidebar.

### Global Settings

Navigate to **SNN AI Chat > Settings** to configure global parameters that apply to all your chat instances unless specifically overridden by individual chat settings.

* **AI Provider Selection:** Choose between OpenRouter or OpenAI.
* **API Keys & Models:** Input your API keys and select the default models for each provider.
* **Shared API Parameters:** Adjust `Temperature`, `Max Response Tokens`, `Top P`, `Frequency Penalty`, and `Presence Penalty` to control the AI's response behavior.
* **General Settings:** Set a `Default System Prompt` and `Default Initial Message` for new chats.

### Managing Individual Chats

Go to **SNN AI Chat > Chats** to create and manage your chat widgets.

* **Add New Chat:** Click "Add New Chat" to create a new AI chat instance.
* **Edit Chat:** Click "Edit" on an existing chat to modify its settings.
    * **Basic Information:**
        * **Chat Name:** A title for your chat widget (e.g., "Customer Support Bot").
        * **Model:** Select a specific AI model for this chat. Leave blank to use the global default.
        * **Initial Message:** The first message displayed to users.
        * **System Prompt:** Instructions for the AI's behavior and personality for this specific chat.
        * **Keep conversation history during session:** Toggle to enable or disable persistent context for the AI.
    * **Styling & Appearance:** Customize colors, sizes, and positioning of the chat widget.
    * **Display Settings:** Control precisely which pages or post types the chat widget appears on. You can use:
        * **Show on all pages:** Displays the chat universally (unless excluded).
        * **Display On Specific Pages:** Enter comma-separated page/post IDs.
        * **Exclude Pages:** Enter comma-separated page/post IDs to prevent the chat from appearing there (overrides all other display settings).
        * **Template Conditions:** Select WordPress template types (Home, Posts, Pages, etc.).
    * **Usage Limits:** Set limits on tokens and chat sessions per user IP per day, and message rate limits per minute.
    * **User Information Collection:** Enable this to prompt users for their name and email before they can start chatting.
* **Delete Chat:** Remove a chat instance.

---

## Usage

Once you've configured your chat instances and their display conditions, the chat widgets will automatically appear on your website's frontend. Users can click the chat toggle button to open the chat interface and start interacting with your AI.

If "Collect user name and email" is enabled for a chat, users will be prompted to enter their details before the chat begins.

---

## Chat History & Analytics

The plugin provides robust tools to monitor chat activity:

* **Dashboard:** The main **SNN AI Chat** dashboard provides an overview of:
    * Active Chats
    * Total Tokens Used
    * Total Sessions
    * Today's Sessions, Messages, and Tokens
    * This Month's Sessions, Messages, and Tokens
    * Recent Chat History (last 5 sessions).
* **Chat History:** Navigate to **SNN AI Chat > Chat History** for a detailed list of all sessions. You can see:
    * Session ID
    * User Name & Email
    * IP Address
    * Number of Messages
    * Total Tokens Used
    * Timestamp
    * **View Details:** Click to see the full transcript of a specific chat session.

---

## Styling & Customization

The plugin leverages inline CSS for dynamic styling based on your settings, allowing for a high degree of customization without needing to write custom code. You can easily modify:

* **Colors:** Primary, Secondary, Text, Widget Background, Input Background, Input Text, Send Button.
* **Dimensions:** Widget width and height, font size, and border radius.
* **Positioning:** Control where the chat bubble appears on your site.

---

## Rate Limiting & Usage Control

To prevent API overages and manage resource consumption, SNN AI CHAT includes comprehensive usage limits:

* **Max Tokens Per Session:** Caps the total tokens used in a single continuous chat session.
* **Max Tokens Per IP Daily:** Limits the total tokens an individual IP address can consume across all sessions in a 24-hour period.
* **Max Chats Per IP Daily:** Restricts the number of new chat sessions an IP address can initiate per day.
* **Rate Limit Per Minute:** Prevents a single user from sending too many messages too quickly.

These limits can be configured per chat instance, offering granular control over your AI's availability and cost.

