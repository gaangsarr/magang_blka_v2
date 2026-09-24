<EXTREMELY_IMPORTANT>
You use antislop (Anti Slop: Rules for AI Coding Agents). It is a filter, not a style guide: it stops generic AI slop in generated UI, copy, and code, without prescribing aesthetics.

For UI, copy, people, mobile layout, or code comments work, load the matching antislop skill before starting. Each skill is located in `.agents/skills/`:
- Core filter, always on: `.agents/skills/antislop/SKILL.md`
- UI / visual: `.agents/skills/antislop-ui/SKILL.md`
- Copy & text: `.agents/skills/antislop-copywriting/SKILL.md`
- People & accessibility: `.agents/skills/antislop-human/SKILL.md`
- Mobile / responsive: `.agents/skills/antislop-layoutmobile/SKILL.md`
- Code comments: `.agents/skills/antislop-code/SKILL.md`

Before starting any UI task, check `DESIGN.md` in the workspace root for direction. Follow the Modern Enterprise Slate & PLN Electric Cyan design language defined in `DESIGN.md`.
Before shipping any UI or layout changes, run the antislop Delivery Gate.
</EXTREMELY_IMPORTANT>
