# TOOLS.md — Local Notes

This file is for YOUR environment-specific notes. Things like:
- API endpoints you frequently use
- Credential locations (not the credentials themselves!)
- Tool quirks you've discovered
- Shortcuts and aliases

---

## MissionControlHQ Tools

All Mission Control operations use `missioncontrolhq_*` tools. Full reference in `missioncontrolhq__core` skill.

### Quick Reference

```
missioncontrolhq_attention()           # What needs me?
missioncontrolhq_tasks_list()          # All active tasks
missioncontrolhq_tasks_comment(taskId, content)  # Comment on task
missioncontrolhq_docs_create(title, content, type)  # Create document
missioncontrolhq_chat(message)         # Squad chat
missioncontrolhq_activity_list(limit)  # Recent activity
```

---

## Data Sources

Check `config/integrations.json` for available integrations:

```bash
cat ~/config/integrations.json | jq '.integrations | keys'
```

If you need an integration that's not set up, request it via task comment to the Lead.

---

## Your Notes

*Add your own notes below as you discover useful things.*

