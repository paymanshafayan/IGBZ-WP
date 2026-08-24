/**
 * فهرست استاندارد پرووایدرها — همان الگویی که Cline دارد:
 * یا از این فهرست انتخاب می‌کنی، یا «سازگار با OpenAI / سازگار با Anthropic» را برمی‌داری
 * و baseURL و کلید خودت را می‌دهی. هیچ محدودیتی از سمت ویرا اضافه نمی‌شود.
 *
 * `kind` فقط می‌گوید پروتکل سیم چیست:
 *   - openai    → POST {baseUrl}/chat/completions
 *   - anthropic → POST {baseUrl}/v1/messages
 *   - mock      → پرووایدر آزمایشی داخلی (بدون شبکه، برای تست خود ابزار)
 */

/**
 * @typedef {Object} ProviderInfo
 * @property {string} id
 * @property {string} label
 * @property {'openai'|'anthropic'|'mock'} kind
 * @property {string} baseUrl
 * @property {boolean} needsKey
 * @property {boolean} editableBaseUrl
 * @property {string} [defaultModel]
 * @property {string[]} [models]      مدل‌های پیشنهادی وقتی پرووایدر فهرست نمی‌دهد
 * @property {boolean} [canListModels] آیا GET {baseUrl}/models دارد
 * @property {string} [docs]
 * @property {string} [note]
 */

/** @type {ProviderInfo[]} */
export const PROVIDERS = [
	{
		id: 'anthropic',
		label: 'Anthropic',
		kind: 'anthropic',
		baseUrl: 'https://api.anthropic.com',
		needsKey: true,
		editableBaseUrl: true,
		canListModels: true,
		defaultModel: 'claude-sonnet-4-5',
		docs: 'https://docs.anthropic.com',
	},
	{
		id: 'openai',
		label: 'OpenAI',
		kind: 'openai',
		baseUrl: 'https://api.openai.com/v1',
		needsKey: true,
		editableBaseUrl: true,
		canListModels: true,
		defaultModel: 'gpt-4.1',
		docs: 'https://platform.openai.com/docs',
	},
	{
		id: 'openrouter',
		label: 'OpenRouter',
		kind: 'openai',
		baseUrl: 'https://openrouter.ai/api/v1',
		needsKey: true,
		editableBaseUrl: true,
		canListModels: true,
		defaultModel: 'anthropic/claude-sonnet-4.5',
		docs: 'https://openrouter.ai/docs',
		note: 'دسترسی به صدها مدل با یک کلید.',
	},
	{
		id: 'google',
		label: 'Google Gemini',
		kind: 'openai',
		baseUrl: 'https://generativelanguage.googleapis.com/v1beta/openai',
		needsKey: true,
		editableBaseUrl: true,
		canListModels: true,
		defaultModel: 'gemini-2.5-pro',
		note: 'از مسیر سازگار با OpenAI گوگل.',
	},
	{
		id: 'deepseek',
		label: 'DeepSeek',
		kind: 'openai',
		baseUrl: 'https://api.deepseek.com/v1',
		needsKey: true,
		editableBaseUrl: true,
		canListModels: true,
		defaultModel: 'deepseek-chat',
	},
	{
		id: 'groq',
		label: 'Groq',
		kind: 'openai',
		baseUrl: 'https://api.groq.com/openai/v1',
		needsKey: true,
		editableBaseUrl: true,
		canListModels: true,
		defaultModel: 'llama-3.3-70b-versatile',
	},
	{
		id: 'mistral',
		label: 'Mistral',
		kind: 'openai',
		baseUrl: 'https://api.mistral.ai/v1',
		needsKey: true,
		editableBaseUrl: true,
		canListModels: true,
		defaultModel: 'mistral-large-latest',
	},
	{
		id: 'xai',
		label: 'xAI (Grok)',
		kind: 'openai',
		baseUrl: 'https://api.x.ai/v1',
		needsKey: true,
		editableBaseUrl: true,
		canListModels: true,
		defaultModel: 'grok-4',
	},
	{
		id: 'together',
		label: 'Together AI',
		kind: 'openai',
		baseUrl: 'https://api.together.xyz/v1',
		needsKey: true,
		editableBaseUrl: true,
		canListModels: true,
	},
	{
		id: 'fireworks',
		label: 'Fireworks AI',
		kind: 'openai',
		baseUrl: 'https://api.fireworks.ai/inference/v1',
		needsKey: true,
		editableBaseUrl: true,
		canListModels: true,
	},
	{
		id: 'cerebras',
		label: 'Cerebras',
		kind: 'openai',
		baseUrl: 'https://api.cerebras.ai/v1',
		needsKey: true,
		editableBaseUrl: true,
		canListModels: true,
	},
	{
		id: 'azure-openai',
		label: 'Azure OpenAI',
		kind: 'openai',
		baseUrl: 'https://<resource>.openai.azure.com/openai/v1',
		needsKey: true,
		editableBaseUrl: true,
		canListModels: false,
		note: 'baseURL را با نام منبع خودت جایگزین کن.',
	},
	{
		id: 'ollama',
		label: 'Ollama (محلی)',
		kind: 'openai',
		baseUrl: 'http://127.0.0.1:11434/v1',
		needsKey: false,
		editableBaseUrl: true,
		canListModels: true,
		note: 'بدون کلید؛ مدل روی دستگاه خودت اجرا می‌شود.',
	},
	{
		id: 'lmstudio',
		label: 'LM Studio (محلی)',
		kind: 'openai',
		baseUrl: 'http://127.0.0.1:1234/v1',
		needsKey: false,
		editableBaseUrl: true,
		canListModels: true,
	},
	{
		id: 'vllm',
		label: 'vLLM (محلی/سرور خودت)',
		kind: 'openai',
		baseUrl: 'http://127.0.0.1:8000/v1',
		needsKey: false,
		editableBaseUrl: true,
		canListModels: true,
	},
	{
		id: 'openai-compatible',
		label: 'سازگار با OpenAI (دلخواه)',
		kind: 'openai',
		baseUrl: '',
		needsKey: true,
		editableBaseUrl: true,
		canListModels: true,
		note: 'هر سرویسی که مسیر /chat/completions استاندارد دارد — از جمله سرویس‌دهنده‌های ایرانی.',
	},
	{
		id: 'anthropic-compatible',
		label: 'سازگار با Anthropic (دلخواه)',
		kind: 'anthropic',
		baseUrl: '',
		needsKey: true,
		editableBaseUrl: true,
		canListModels: false,
		note: 'هر سرویسی که مسیر /v1/messages استاندارد دارد.',
	},
	{
		id: 'mock',
		label: 'آزمایشی داخلی (بدون شبکه)',
		kind: 'mock',
		baseUrl: '',
		needsKey: false,
		editableBaseUrl: false,
		canListModels: false,
		defaultModel: 'vira-mock-1',
		note: 'برای امتحان‌کردن خود ابزار بدون کلید و بدون اینترنت.',
	},
];

/** @param {string} id */
export function providerInfo( id ) {
	return PROVIDERS.find( ( p ) => p.id === id ) || null;
}
