import os
import re
import datetime

def extract_strings(directory):
    strings = {}
    
    # Simple regex that captures ('string', 'domain') or ("string", "domain")
    # It handles escaped quotes inside the string
    
    # Pattern 1: Single quotes for string
    p1 = re.compile(r"""(?:__|_e|_x|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_n|_nx)\s*\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*['"]marrison-custom-updater['"]""", re.DOTALL)
    
    # Pattern 2: Double quotes for string
    p2 = re.compile(r"""(?:__|_e|_x|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_n|_nx)\s*\(\s*"((?:[^"\\]|\\.)*)"\s*,\s*['"]marrison-custom-updater['"]""", re.DOTALL)

    patterns = [p1, p2]

    for root, dirs, files in os.walk(directory):
        for file in files:
            if file.endswith(".php") and file != "generate_pot.py":
                filepath = os.path.join(root, file)
                relpath = os.path.relpath(filepath, directory).replace('\\', '/')
                
                with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
                    content = f.read()
                    
                    for pattern in patterns:
                        matches = pattern.finditer(content)
                        for match in matches:
                            string = match.group(1)
                            
                            # Find line number (approximate)
                            lineno = content[:match.start()].count('\n') + 1
                            
                            if string not in strings:
                                strings[string] = []
                            strings[string].append(f"{relpath}:{lineno}")
                            
    return strings

def generate_pot(strings, output_file):
    now = datetime.datetime.now().strftime('%Y-%m-%d %H:%M+0000')
    
    content = f'''msgid ""
msgstr ""
"Project-Id-Version: Marrison Custom Updater\\n"
"Report-Msgid-Bugs-To: \\n"
"POT-Creation-Date: {now}\\n"
"PO-Revision-Date: \\n"
"Last-Translator: \\n"
"Language-Team: \\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"X-Generator: TraeAI\\n"
"X-Domain: marrison-custom-updater\\n"

'''
    
    for string in sorted(strings.keys()):
        # Escape double quotes for msgid if not already escaped (this is tricky, so we rely on what we captured)
        # If the capture was from single quotes, we might have unescaped ' but " are raw.
        # If from double quotes, " are escaped.
        
        # Actually, let's just clean up the captured string
        # If it was single quoted: 'It\'s me' -> It\'s me. We want: It's me
        # If it was double quoted: "Say \"Hello\"" -> Say \"Hello\". We want: Say "Hello"
        
        # But wait, .pot requires "..." for msgid.
        # So we need to ensure internal " are escaped.
        
        # Let's simplify:
        # 1. Unescape the PHP string to get the raw string
        # 2. Escape it for PO format
        
        # Since I can't easily know which regex matched in this loop structure without passing info, 
        # I'll rely on a basic unescape helper.
        
        # Actually, the regex group captures the content INSIDE the quotes.
        # So for 'It\'s', we captured It\'s.
        # For "He said \"Hi\"", we captured He said \"Hi\".
        
        # Python's string-escape is useful here but we need to be careful.
        
        # Let's do a best-effort unescape
        raw_string = string.replace("\\'", "'").replace('\\"', '"').replace('\\\\', '\\')
        
        # Now escape for PO
        msgid = raw_string.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n')
        
        locations = strings[string]
        locations = list(dict.fromkeys(locations))
        
        loc_str = ""
        current_line = "#: "
        for loc in locations:
            if len(current_line) + len(loc) + 1 > 78:
                loc_str += current_line.rstrip() + "\n"
                current_line = "#: " + loc + " "
            else:
                current_line += loc + " "
        loc_str += current_line.rstrip()
        
        content += f"{loc_str}\n"
        content += f'msgid "{msgid}"\n'
        content += 'msgstr ""\n\n'
        
    with open(output_file, 'w', encoding='utf-8') as f:
        f.write(content)

if __name__ == "__main__":
    current_dir = os.getcwd()
    strings = extract_strings(current_dir)
    output_path = os.path.join(current_dir, 'languages', 'marrison-custom-updater.pot')
    generate_pot(strings, output_path)
    print(f"Generated .pot file at {output_path} with {len(strings)} strings.")
