# git-cp: Smart commit and push

Automatically generates commit message based on changes and pushes to remote.

## Usage

```
/git-cp
```

This runs:
1. `git add -A` - stage all changes
2. Analyze changes (git diff --stat, git status)  
3. Generate smart commit message based on what changed
4. `git commit -m "..."` - commit with auto-generated message
5. `git push` - push to remote

## Auto-generated commit messages

- Adds new file → "Add {filename}"
- Adds multiple files → "Add {n} new files: {files}"
- Updates existing → "Update {filename}" or "Update {n} files"
- Mixed changes → "Add {new} and update {updated} files"
- Removes files → "Remove {filename}"

## Notes

- If no changes → outputs "Nothing to commit"
- Uses git status --short for quick analysis
- Shows diff stat for context in message