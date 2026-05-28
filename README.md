# @imenam/refactoring-mcp

An MCP (Model Context Protocol) server that lets AI agents copy or move blocks of code between files, with optional directory confinement for safety.

## Tools

### `move_or_copy_code`

Copies or moves a range of lines from a source file to a destination file.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `sourceFile` | `string` | ✅ | Absolute path to the source file |
| `startLine` | `number` | ✅ | Start line of the block (1-indexed) |
| `endLine` | `number` | ✅ | End line of the block, inclusive (1-indexed) |
| `operationType` | `"copy" \| "cut"` | ✅ | `copy` keeps the source intact, `cut` removes the lines |
| `destinationFile` | `string` | ✅ | Absolute path to the destination file (created automatically if it doesn't exist) |
| `destinationLine` | `number` | ❌ | Line at which to insert in the destination (appended at end by default) |

### `get_file_outline`

Returns the list of top-level symbols in a file (functions, classes, interfaces, types, constants, methods) with their start and end line numbers. Useful for identifying the exact coordinates of a code block before moving it.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `filePath` | `string` | ✅ | Absolute path to the file to analyse |

## Configuration

All file paths must be **absolute**. Relative paths are rejected.

To restrict operations to a specific directory, set the `REFACTORING_ALLOWED_DIR` environment variable. Any attempt to access a file outside that directory will be blocked.

```env
# .env
REFACTORING_ALLOWED_DIR=C:/Users/you/my-project
```

## Installation

```bash
npm install -g @imenam/refactoring-mcp
```

Or use it directly with `npx`:

```json
{
  "refactoring-mcp": {
    "command": "npx",
    "args": ["-y", "@imenam/refactoring-mcp"]
  }
}
```

Or point to a local build:

```json
{
  "refactoring-mcp": {
    "command": "node",
    "args": ["/absolute/path/to/refactoring-mcp/dist/index.js"],
    "env": {
      "REFACTORING_ALLOWED_DIR": "/absolute/path/to/your/project"
    }
  }
}
```

## License

MIT
