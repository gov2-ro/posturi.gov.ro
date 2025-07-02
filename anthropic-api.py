

import anthropic
import os
import PyPDF2
import docx
import sys



# os.environ["ANTHROPIC_API_KEY"] = ""

from dotenv import load_dotenv
import os

# read api key fron .env file
load_dotenv()
os.environ["ANTHROPIC_API_KEY"] = os.getenv("ANTHROPIC_API_KEY")



def read_file(file_path):
    _, file_extension = os.path.splitext(file_path)
    
    if file_extension.lower() == '.pdf':
        with open(file_path, 'rb') as file:
            pdf_reader = PyPDF2.PdfReader(file)
            return ' '.join(page.extract_text() for page in pdf_reader.pages)
    
    elif file_extension.lower() == '.docx':
        doc = docx.Document(file_path)
        return ' '.join(paragraph.text for paragraph in doc.paragraphs)
    
    elif file_extension.lower() == '.doc':
        raise NotImplementedError("Reading .doc files is not supported. Please convert to .docx and try again.")
    
    else:
        with open(file_path, 'r', encoding='utf-8') as file:
            return file.read()

 
# Initialize the Anthropic client
client = anthropic.Anthropic()

# Get file path and prompt from command line arguments
if len(sys.argv) < 3:
    print("Usage: python script.py <file_path> <prompt>")
    sys.exit(1)

file_path = sys.argv[1]
prompt = sys.argv[2]

# Read the document
try:
    document = read_file(file_path)
except Exception as e:
    print(f"Error reading file: {e}")
    sys.exit(1)

# Make the API call using the Messages API
try:
    message = client.messages.create(
        # model="claude-3-haiku-20240307",
        model="claude-3-5-sonnet-20240620",
        max_tokens=2000,
        messages=[
            {
                "role": "user",
                "content": f"Here's a document: {document}\n\nPrompt: {prompt}"
            }
        ]
    )
    print(message.content[0].text)
except Exception as e:
    print(f"Error calling Anthropic API: {e}")