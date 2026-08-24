/**
 * یک سرور MCP کوچک و واقعی، فقط برای تست.
 *
 * از API سطح‌پایین استفاده می‌کند تا به zod و بقیهٔ وابستگی‌های اختیاری نیاز نداشته باشد،
 * و کنار خود پروژه می‌ماند تا `@modelcontextprotocol/sdk` را پیدا کند.
 */

import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import {
	ListToolsRequestSchema,
	CallToolRequestSchema,
	ListPromptsRequestSchema,
	GetPromptRequestSchema,
	ListResourcesRequestSchema,
	ReadResourceRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';

const server = new Server(
	{ name: 'demo', version: '1.0.0' },
	{ capabilities: { tools: {}, prompts: {}, resources: {} } }
);

server.setRequestHandler( ListToolsRequestSchema, async () => ( {
	tools: [
		{
			name: 'add',
			description: 'جمع دو عدد',
			inputSchema: {
				type: 'object',
				properties: { a: { type: 'number' }, b: { type: 'number' } },
				required: [ 'a', 'b' ],
			},
		},
		{
			name: 'boom',
			description: 'همیشه خطا می‌دهد',
			inputSchema: { type: 'object', properties: {} },
		},
	],
} ) );

server.setRequestHandler( CallToolRequestSchema, async ( request ) => {
	const { name, arguments: args } = request.params;

	if ( name === 'add' ) {
		return { content: [ { type: 'text', text: String( Number( args.a ) + Number( args.b ) ) } ] };
	}
	if ( name === 'boom' ) {
		return { isError: true, content: [ { type: 'text', text: 'خرابی عمدی' } ] };
	}
	return { isError: true, content: [ { type: 'text', text: 'ابزار ناشناخته' } ] };
} );

// پرامپت‌ها — در هوشا به دستور اسلش تبدیل می‌شوند.
server.setRequestHandler( ListPromptsRequestSchema, async () => ( {
	prompts: [ { name: 'greet', description: 'یک سلام رسمی می‌سازد' } ],
} ) );

server.setRequestHandler( GetPromptRequestSchema, async ( request ) => {
	const who = request.params.arguments?.input || 'دنیا';
	return {
		messages: [ { role: 'user', content: { type: 'text', text: `به ${ who } سلام رسمی بگو.` } } ],
	};
} );

// منابع — با ابزار read_mcp_resource خوانده می‌شوند.
server.setRequestHandler( ListResourcesRequestSchema, async () => ( {
	resources: [ { uri: 'demo://note', name: 'یادداشت نمونه', mimeType: 'text/plain' } ],
} ) );

server.setRequestHandler( ReadResourceRequestSchema, async ( request ) => ( {
	contents: [ { uri: request.params.uri, mimeType: 'text/plain', text: 'محتوای منبع نمونه' } ],
} ) );

await server.connect( new StdioServerTransport() );
