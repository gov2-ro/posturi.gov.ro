import openai
from docx import Document

# Replace this with your actual OpenAI API key
openai.api_key = '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'

 

# Load the document
def load_file(file_path):
    with open(file_path, 'r', encoding='utf-8') as file:
        return file.read()

def load_docx(file_path):
    doc = Document(file_path)
    full_text = []
    for paragraph in doc.paragraphs:
        full_text.append(paragraph.text)
    return '\n'.join(full_text)

# Define your document and prompt
file_path = 'data/downloads/41d71860.docx'  # Path to your docx, doc, or pdf file
file_content = load_docx(file_path)
prompt = "Convert the attached job posting to schema.org/JobPosting JSON-LD format. return the JSON-LD object."

# Send the document and prompt to GPT API
response = openai.ChatCompletion.create(
    model="gpt-3.5-turbo",  # or "gpt-4"
    messages=[
        {"role": "system", "content": "You are a helpful assistant."},
        {"role": "user", "content": prompt}
    ]
)

# Print the response
# print(response['choices'][0]['message']['content'])

print(response['choices'][0]['message']['content'])


