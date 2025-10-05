<!DOCTYPE html>
<html lang="en" id="websiteHtml" class="">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">    <title>DAN-LEN</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    
    <!-- Tailwind CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- IMPORTANT: Configure Tailwind AFTER loading the CDN -->
    <script>
        if (typeof tailwind !== 'undefined') {
            tailwind.config = {
                darkMode: 'class',
            }
        }
    </script>

    <style>
        :root {
            --primary-color: #4f46e5;
        }

        .dark {
            --primary-color: #8b5cf6;
        }

        body {
            font-family: 'Inter', sans-serif;
        }
        
        /* Basic scrollbar styling */
        ::-webkit-scrollbar {
            width: 8px;
        }
        ::-webkit-scrollbar-thumb {
            background-color: #ccc;
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background-color: #999;
        }
        
        .disabled-feature {
            opacity: 0.5;
            pointer-events: none;
            filter: grayscale(100%);
        }

        #settingsPanel {
            transition: transform 0.3s ease-in-out;
            transform: translateX(100%);
            z-index: 50; 
        }

        #settingsPanel.open {
            transform: translateX(0);
        }

        #saveConfirmation {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.5s ease-in-out;
            z-index: 100;
        }
        #saveConfirmation.visible {
            opacity: 1;
        }
    </style>
</head>
<body class="bg-gray-100 text-gray-900 dark:bg-black dark:text-gray-100 min-h-screen flex flex-col items-center p-4 sm:p-8">
    
    <!-- Main Content -->
    <main class="w-full max-w-4xl p-6 md:p-10 bg-white dark:bg-neutral-800 shadow-xl rounded-2xl flex-grow overflow-y-auto">
        
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl sm:text-4xl font-bold tracking-tight text-[var(--primary-color)]">
                Welcome to My Website
            </h1>
            <!-- The SVG icon has been restored -->
            <button id="openSettingsBtn" class="bg-[var(--primary-color)] text-white px-4 py-2 rounded-xl shadow-lg hover:shadow-xl transition-all duration-300">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-6 h-6">
                    <path fill-rule="evenodd" d="M11.078 2.25c-.917 0-1.699.663-1.85 1.567L9.165 4.96a1.858 1.858 0 0 1-.983 1.155l-1.72.639a1.857 1.857 0 0 0-1.977.809 1.857 1.857 0 0 0-.256 1.76l.305 1.72a1.858 1.858 0 0 1-.482 1.857l-1.085 1.085A1.858 1.858 0 0 0 2.25 12c0 .917.663 1.699 1.567 1.85l1.72.305a1.858 1.858 0 0 1 1.155.983l.639 1.72a1.857 1.857 0 0 0 .809 1.977 1.857 1.857 0 0 0 1.76.256l1.72-.305a1.858 1.858 0 0 1 1.857.482l1.085 1.085A1.858 1.858 0 0 0 12 21.75c.917 0 1.699-.663 1.85-1.567l.305-1.72a1.858 1.858 0 0 1 .983-1.155l1.72-.639a1.857 1.857 0 0 0 1.977-.809 1.857 1.857 0 0 0 .256-1.76l-.305-1.72a1.858 1.858 0 0 1 .482-1.857l1.085-1.085A1.858 1.858 0 0 0 21.75 12c0-.917-.663-1.699-1.567-1.85l-1.72-.305a1.858 1.858 0 0 1-1.155-.983l-.639-1.72a1.857 1.857 0 0 0-.809-1.977 1.857 1.857 0 0 0-1.76-.256l-1.72.305a1.858 1.858 0 0 1-1.857-.482L12.03 3.653A1.857 1.857 0 0 0 11.078 2.25Z" />
                    <path d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                </svg>
            </button>
        </div>

        <p class="text-gray-700 dark:text-gray-300 mb-6 leading-relaxed">
            This is a sample website content. You can use the settings panel to the right to change the look and feel of the page in real-time.
        </p>

        <!-- Comments Section -->
        <div id="commentsSection" class="p-4 bg-gray-50 dark:bg-neutral-800 rounded-lg shadow-inner mb-6 transition-all duration-300">
            <h2 class="text-xl font-semibold mb-2 text-[var(--primary-color)]">Comments</h2>
            <p class="text-gray-600 dark:text-gray-400">
                This section contains user comments. You can disable this feature from the settings panel.
            </p>
        </div>

        <!-- Sharing Buttons -->
        <div id="sharingButtons" class="flex justify-center space-x-4 p-4 bg-gray-50 dark:bg-neutral-800 rounded-lg shadow-inner transition-all duration-300">
            <button class="bg-[var(--primary-color)] text-white px-4 py-2 rounded-xl">Share on X</button>
            <button class="bg-[var(--primary-color)] text-white px-4 py-2 rounded-xl">Share on Facebook</button>
        </div>
        
    </main>

    <!-- Settings Panel -->
    <aside id="settingsPanel" class="fixed top-0 right-0 h-full w-full max-w-xs bg-white dark:bg-neutral-900 shadow-2xl p-6 overflow-y-auto">
        <div class="flex justify-between items-center mb-8">
            <h2 class="text-2xl font-bold">Settings</h2>
            <button id="closeSettingsBtn" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-6 h-6">
                    <path fill-rule="evenodd" d="M5.47 5.47a.75.75 0 0 1 1.06 0L12 10.94l5.47-5.47a.75.75 0 1 1 1.06 1.06L13.06 12l5.47 5.47a.75.75 0 1 1-1.06 1.06L12 13.06l-5.47 5.47a.75.75 0 0 1-1.06-1.06L10.94 12 5.47 6.53a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                </svg>
            </button>
        </div>

        <!-- Dark Mode Toggle -->
        <div class="mb-6">
            <label for="darkModeToggle" class="block text-lg font-semibold mb-2">Dark Mode</label>
            <div class="relative inline-block w-14 mr-2 align-middle select-none transition duration-200 ease-in">
                <input type="checkbox" id="darkModeToggle" class="toggle-checkbox absolute block w-8 h-8 rounded-full bg-white dark:bg-gray-900 border-4 appearance-none cursor-pointer transition-all duration-300 checked:right-0 checked:bg-[var(--primary-color)] checked:border-white">
                <label for="darkModeToggle" class="block overflow-hidden h-8 rounded-full bg-gray-300 dark:bg-gray-700 cursor-pointer"></label>
            </div>
        </div>

        <!-- Accent Color -->
        <div class="mb-6">
            <label for="colorPicker" class="block text-lg font-semibold mb-2">Accent Color</label>
            <div class="flex items-center space-x-4">
                <input type="color" id="colorPicker" value="#4f46e5" class="w-12 h-12 rounded-full border-2 border-gray-300 dark:border-gray-600 cursor-pointer">
                <span id="colorHex" class="text-sm font-mono text-gray-600 dark:text-gray-400">#4f46e5</span>
            </div>
        </div>

        <!-- Feature Toggles -->
        <div class="mb-6">
            <label class="block text-lg font-semibold mb-2">Manage Features</label>
            <div class="flex items-center mb-2">
                <input id="commentsToggle" type="checkbox" checked class="form-checkbox text-[var(--primary-color)] h-5 w-5 rounded">
                <label for="commentsToggle" class="ml-2 text-gray-700 dark:text-gray-300">Enable Comments</label>
            </div>
            <div class="flex items-center">
                <input id="sharingToggle" type="checkbox" checked class="form-checkbox text-[var(--primary-color)] h-5 w-5 rounded">
                <label for="sharingToggle" class="ml-2 text-gray-700 dark:text-gray-300">Enable Sharing Buttons</label>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="mt-8 space-y-4">
            <button id="saveSettingsBtn" class="w-full bg-[var(--primary-color)] text-white py-3 rounded-xl hover:opacity-80 transition-all duration-300">
                Save Changes
            </button>
            <button id="resetSettingsBtn" class="w-full bg-gray-200 text-gray-800 py-3 rounded-xl hover:bg-gray-300 transition-all duration-300">
                Reset to Default
            </button>
        </div>
    </aside>

    <div id="overlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 hidden"></div>
    <div id="saveConfirmation" class="p-4 bg-green-500 text-white rounded-lg shadow-xl hidden">
        Settings saved!
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const html = document.documentElement;
            const openSettingsBtn = document.getElementById('openSettingsBtn');
            const closeSettingsBtn = document.getElementById('closeSettingsBtn');
            const settingsPanel = document.getElementById('settingsPanel');
            const overlay = document.getElementById('overlay');
            const darkModeToggle = document.getElementById('darkModeToggle');
            const colorPicker = document.getElementById('colorPicker');
            const colorHexSpan = document.getElementById('colorHex');
            const commentsToggle = document.getElementById('commentsToggle');
            const sharingToggle = document.getElementById('sharingToggle');
            const commentsSection = document.getElementById('commentsSection');
            const sharingButtons = document.getElementById('sharingButtons');
            const saveSettingsBtn = document.getElementById('saveSettingsBtn');
            const resetSettingsBtn = document.getElementById('resetSettingsBtn');
            const saveConfirmation = document.getElementById('saveConfirmation');

            let unsavedChanges = false;
            let initialSettings = {};

            const saveSettings = () => {
                const settings = {
                    darkMode: html.classList.contains('dark'),
                    primaryColor: colorPicker.value,
                    commentsEnabled: commentsToggle.checked,
                    primaryColor: colorPicker.value
                };
                localStorage.setItem('websiteSettings', JSON.stringify(settings));
                unsavedChanges = false;
                showConfirmation();
            };

            const showConfirmation = () => {
                saveConfirmation.classList.remove('hidden');
                saveConfirmation.classList.add('visible');
                setTimeout(() => {
                    saveConfirmation.classList.remove('visible');
                    saveConfirmation.classList.add('hidden');
                }, 3000);
            };

            const applySettings = (settings) => {
                // Apply dark mode
                if (settings.darkMode) {
                    html.classList.add('dark');
                    darkModeToggle.checked = true;
                } else {
                    html.classList.remove('dark');
                    darkModeToggle.checked = false;
                }

                // Apply primary color
                html.style.setProperty('--primary-color', settings.primaryColor);
                colorPicker.value = settings.primaryColor;
                colorHexSpan.textContent = settings.primaryColor;

                // Apply feature toggles
                commentsToggle.checked = settings.commentsEnabled;
                sharingToggle.checked = settings.sharingEnabled;

                // Toggle visibility of sections
                commentsSection.classList.toggle('disabled-feature', !settings.commentsEnabled);
                sharingButtons.classList.toggle('disabled-feature', !settings.sharingEnabled);
            };

            const loadSettings = () => {
                const savedSettings = JSON.parse(localStorage.getItem('websiteSettings')) || {};
                const savedTheme = localStorage.getItem("theme");
                
                // Determine dark mode state from saved data or system preference
                let isDarkMode;
                if (savedSettings.hasOwnProperty('darkMode')) {
                    isDarkMode = savedSettings.darkMode;
                } else if (savedTheme) {
                    isDarkMode = savedTheme === "dark";
                } else {
                    isDarkMode = window.matchMedia('(prefers-color-scheme: dark)').matches;
                }

                // Default settings if none are saved
                const settingsToApply = {
                    darkMode: isDarkMode,
                    primaryColor: savedSettings.primaryColor || '#4f46e5',
                    commentsEnabled: savedSettings.hasOwnProperty('commentsEnabled') ? savedSettings.commentsEnabled : true,
                    primaryColor: savedSettings.primaryColor || '#4f46e5'
                };

                applySettings(settingsToApply);
            };
            
            const resetSettings = () => {
                localStorage.removeItem('websiteSettings');
                localStorage.removeItem('theme');
                location.reload();
            };

            openSettingsBtn.addEventListener('click', () => {
                // Save a snapshot of the current settings before opening the panel
                initialSettings = {
                    darkMode: html.classList.contains('dark'),
                    primaryColor: colorPicker.value,
                    commentsEnabled: commentsToggle.checked,
                    sharingEnabled: sharingToggle.checked
                };
                settingsPanel.classList.add('open');
                overlay.classList.remove('hidden');
            });

            closeSettingsBtn.addEventListener('click', () => {
                // If there are unsaved changes, revert the UI to the initial state
                if (unsavedChanges) {
                    applySettings(initialSettings);
                    unsavedChanges = false;
                }
                settingsPanel.classList.remove('open');
                overlay.classList.add('hidden');
            });

            overlay.addEventListener('click', () => {
                closeSettingsBtn.click();
            });

            // The following listeners now simply mark that a change has occurred
            darkModeToggle.addEventListener('change', () => {
                unsavedChanges = true;
                html.classList.toggle('dark', darkModeToggle.checked);
            });

            colorPicker.addEventListener('input', (e) => {
                unsavedChanges = true;
                const newColor = e.target.value;
                html.style.setProperty('--primary-color', newColor);
                colorHexSpan.textContent = newColor;
            });

            commentsToggle.addEventListener('change', () => {
                unsavedChanges = true;
                commentsSection.classList.toggle('disabled-feature', !commentsToggle.checked);
            });

            sharingToggle.addEventListener('change', () => {
                unsavedChanges = true;
                sharingButtons.classList.toggle('disabled-feature', !sharingToggle.checked);
            });
            
            saveSettingsBtn.addEventListener('click', saveSettings);
            resetSettingsBtn.addEventListener('click', resetSettings);

            // Load settings on page load
            loadSettings();
        });
    </script>
</body>
</html>
